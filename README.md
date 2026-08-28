# UbermudaHealthCheckBundle

Infrastructure diagnostics for Symfony, plus the liveness endpoint a load balancer polls.

Two things ship here, and they answer different questions:

- **`GET /healthz`** — can this container serve a request that reaches the database?
  Unauthenticated, two states, no detail. For probes.
- **Diagnostics** — is the surrounding infrastructure actually wired up: mail, the
  messenger worker, the Mercure hub? Four states, a translated sentence each, and a
  console command. For an operator.

The point of the second one is that it never guesses. A check that cannot observe
something reports `unknown` and says why, rather than showing a green tick nobody
earned.

## Installation

```bash
composer require ubermuda/health-check-bundle
```

Register the bundle (Symfony Flex does this automatically):

```php
// config/bundles.php
return [
    // ...
    Ubermuda\HealthCheckBundle\UbermudaHealthCheckBundle::class => ['all' => true],
];
```

### The endpoint is off until you import its route

Nothing is mounted by default. The import *is* the on/off switch — there is no
enable flag:

```yaml
# config/routes/ubermuda_health_check.yaml
ubermuda_health_check:
    resource: '@UbermudaHealthCheckBundle/config/routes.php'
```

If your app has a firewall, `/healthz` must be reachable anonymously, or the probe
gets a redirect to your login page and reports the container as healthy.

## Configuration

Both keys are optional; defaults shown:

```yaml
# config/packages/ubermuda_health_check.yaml
ubermuda_health_check:
    path: /healthz     # where the endpoint mounts, when you import the routes
    probe_token: ''    # empty means sensitive metadata never appears
```

`probe_token` is a shared secret a caller presents as an `X-Probe-Token` **header**
— never a query parameter, which access logs record and `Referer` forwards. Read it
from the environment so it is not in your repository:

```yaml
ubermuda_health_check:
    probe_token: '%env(default::HEALTH_PROBE_TOKEN)%'
```

## The endpoint

```console
$ curl -s https://example.com/healthz
{"status":"ok"}
```

`200` with `{"status":"ok"}` while the database answers, `503` with
`{"status":"error"}` when it does not, and `Cache-Control: no-store` either way — a
cached health check reports the state of a container that may since have died.

Nothing else is in the body by default. Everything an operator would find useful
here is something an anonymous caller must not learn: the connection error carries
the database host and user; the build version tells an attacker which advisories
apply. Those belong on an authenticated page, or behind the probe token.

### Adding fields: HealthMetadataProvider

To report anything more, contribute a provider. It is picked up by tag, so nothing
central has to know it exists:

```php
use Ubermuda\HealthCheckBundle\HealthMetadataProvider;

final readonly class BuildMetadata implements HealthMetadataProvider
{
    public function __construct(private ?string $version)
    {
    }

    public function fields(): array
    {
        return ['version' => $this->version];
    }

    public function sensitive(): bool
    {
        return true;
    }
}
```

```console
$ curl -s -H "X-Probe-Token: $HEALTH_PROBE_TOKEN" https://example.com/healthz
{"status":"ok","version":"77bc23c"}
```

The rules, all of which the endpoint enforces:

- **Sensitive by default in practice.** `sensitive()` has no default — every provider
  has to state it — and `true` means the fields reach only a caller presenting the
  correct probe token. With no token configured, they never appear at all.
- **`status` is the endpoint's own verdict** and cannot be contributed. A provider
  returning it throws, because otherwise a contributor could make a failing instance
  answer `ok`. Two providers contributing the same key throw for the same reason.
- **No I/O.** A load balancer hits this every few seconds. No query, no HTTP call, no
  file read per request. Anything that has to look something up is a diagnostic
  check, not metadata.
- **Null is dropped.** An absent value means the key simply does not appear.

The payload is flat: no grouping, no nesting, no ordering guarantees.

## Diagnostics

```console
$ bin/console health-check:status
 ------- ------------------- ------------------------------------------------------
  State   Check               Detail
 ------- ------------------- ------------------------------------------------------
  FAILED  Mail transport      MAILER_DSN is the shipped default null://null. …
  WARNING Sender address      MAILER_FROM_ADDRESS is still on the placeholder …
  UNKNOWN Background worker   The queue is empty. An idle queue cannot prove a …
  OK      Failed messages     Nothing in the failed transport. …
  WARNING Mercure hub         No Mercure hub is configured (MERCURE_URL and …).
 ------- ------------------- ------------------------------------------------------

 ! [NOTE] 1 check(s) reported unknown. Unknown is not a pass: nothing was observed
 !        either way, so verify those by hand.
```

Exit code is non-zero when any check failed; `--strict` also fails on a warning.
`unknown` never affects it — a green exit that cannot tell "working" from "nothing
running" is worse than no check at all.

To render the same report in your own UI, inject `RunDiagnosticsHandler` and invoke
it. It returns a `RunDiagnosticsView` with the checks in priority order and
`overall`, the worst state among them.

### The shipped checks

| Key | What it observes |
|---|---|
| `mailer` | Builds `MAILER_DSN` and, when it speaks SMTP, opens a real connection. A non-SMTP transport is `unknown`, never assumed working. |
| `mailer_sender` | Whether `MAILER_FROM_ADDRESS` is still on the `@localhost` placeholder domain, which no real mail server accepts. |
| `worker` | Whether queued messages are being cleared, including a claim a dead worker abandoned. |
| `failed_messages` | The `failed` transport, which nothing else in a UI mentions. |
| `mercure` | Whether a hub answers at `MERCURE_URL`. Any HTTP answer counts; only a transport error means "not there". |

The queue checks read `messenger_messages` directly and use PostgreSQL/SQLite
`FILTER` syntax. `MERCURE_URL`, `MERCURE_JWT_SECRET`, `MAILER_DSN` and
`MAILER_FROM_ADDRESS` are all read through `default::`, so an instance that has not
set them gets a diagnosis rather than a fatal error.

### Writing a check

```php
use Ubermuda\HealthCheckBundle\Diagnostic;
use Ubermuda\HealthCheckBundle\DiagnosticInterface;
use Ubermuda\HealthCheckBundle\DiagnosticState;

final readonly class StripeCheck implements DiagnosticInterface
{
    public function __construct(private bool $billingEnabled, private ?string $secretKey)
    {
    }

    public static function priority(): int
    {
        return 20;
    }

    public function __invoke(): ?Diagnostic
    {
        if (!$this->billingEnabled) {
            return null;
        }

        if (null === $this->secretKey || '' === $this->secretKey) {
            return new Diagnostic('stripe', DiagnosticState::Failed, 'status.stripe.missing');
        }

        return new Diagnostic('stripe', DiagnosticState::Ok, 'status.stripe.configured');
    }
}
```

Implementing the interface is enough — `#[AutoconfigureTag]` on it registers the
service under `ubermuda_health_check.diagnostic`. `priority()` orders the report,
highest first.

#### `null` and `Unknown` are different answers, and this is the part that matters

- **`null` — the check does not apply here.** Billing is switched off, so there is
  nothing to say about Stripe. The row is left out of the report entirely. A green
  tick and a warning would both be claims the operator did not ask for.
- **`DiagnosticState::Unknown` — the check applies, and nothing could be observed.**
  A running messenger worker leaves no lasting trace, so an idle queue is consistent
  with both a healthy worker and no worker at all. The row appears, saying exactly
  that.

The temptation in both cases is to report `Ok`, because a page of green ticks looks
like a working instance. That is how an operator ends up trusting a status page that
proves nothing. If you cannot observe it, say so.

#### Detail is a translation key, never a sentence

`$detail` and `$detailParameters` are a message id and its placeholders, so the
report stays translatable and — the reason it is a rule rather than a preference —
so a check cannot put a DSN, a key or a password on screen by interpolating an
exception message.

`$domain` defaults to `messages`, your application's own catalogue. Both the detail
and the check's label, which is `check.<key>.label`, are resolved in it — so the
example above needs `check.stripe.label` alongside its two detail messages.

The bundle's own checks pass `UbermudaHealthCheckBundle::TRANSLATION_DOMAIN`
(`ubermuda_health_check`), whose English catalogue ships in `translations/`. It also
carries `state.ok`, `state.warning`, `state.unknown` and `state.failed` for rendering
the states themselves.

## Requirements

PHP 8.5+, Symfony 8.1+, and a Doctrine DBAL connection. `symfony/mailer` and
`symfony/http-client` are hard requirements because the shipped checks use them;
Mercure is *not* a dependency at all — that check is an HTTP request to a URL.
