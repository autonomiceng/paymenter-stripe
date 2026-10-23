# Convert an existing StripeSaved install

Production never installed StripeSaved. Fresh installs use AE Stripe directly.
This command is only for an existing development or staging install. It preserves
the gateway ID, settings IDs and values, saved agreements, service references,
fee rows and retry keys. It does not contact Stripe or create a gateway row.

1. Back up the database and extension files. Finish any bundled-adoption rollback
   before conversion; old private adoption manifests remain historical backups.
2. Pause checkout, account method setup, scheduler, queue workers and collection.
   Drain active work and stop webhook delivery. Keep writers paused throughout.
3. Extract `AeStripe.zip` and copy its `AeStripe/` directory directly to
   `extensions/Gateways/AeStripe/`. Keep the old directory until conversion succeeds.
   Do not use the native uploader or call install/upgrade first: those hooks create
   the target fee table and conversion refuses when both table names exist.
4. From the Paymenter root, explicitly load the command. The old database row
   still boots StripeSaved, so ordinary Artisan discovery cannot load the new class:

   ```sh
   ae_convert() {
     php -r '
       require "vendor/autoload.php";
       $app = require "bootstrap/app.php";
       $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
       require_once "extensions/Gateways/AeStripe/ConvertFromStripeSavedCommand.php";
       $artisan = new Illuminate\Console\Application($app, $app->make("events"), $app->version());
       $artisan->add(new Paymenter\Extensions\Gateways\AeStripe\ConvertFromStripeSavedCommand);
       $parameters = in_array("--apply", $argv, true) ? ["--apply" => true] : [];
       exit($artisan->call("ae-stripe:convert-from-stripe-saved", $parameters,
           new Symfony\Component\Console\Output\ConsoleOutput));
     ' -- "$@"
   }
   ae_convert
   # Review the output, then apply:
   ae_convert --apply
   ae_convert
   ```

The command defaults to dry run. It refuses if both extension identities exist,
including soft-deleted rows, or if a target table, column or property/setting key
conflicts with a source. Custom gateway names stay unchanged; the old default name
becomes **AE Stripe**. Native shared keys such as `stripe_id` and `stripe_secret_key`
stay unchanged. Extension property keys, audit events and migration names move to
the new prefix. Encrypted setting values and immutable Stripe retry payloads stay
unchanged. No native prefixed columns exist in this release; any old prefixed
columns present in a customized install are renamed in place.

MySQL schema changes commit implicitly. The command checks the plan first, runs
schema changes outside transactions, then rechecks and updates rows in a new
transaction. An interrupted schema conversion can resume by running dry run and
apply again. Do not resume writers after a failure; inspect the schema and rows.
A database restore with the matching old files is the rollback path.

After success, verify row IDs and counts, clear application/view caches and restart
workers. `/extensions/stripe-saved/webhook` remains an alias of the AE Stripe
webhook handler, using the preserved signing secret and signature checks. The command
prints the recommended new URL, `/extensions/ae-stripe/webhook`. Update the existing
endpoint URL in Stripe when convenient; keep the same endpoint and signing secret.
The command does not contact Stripe or change its endpoint configuration.
Existing sandbox object metadata is read under both prefixes, with new keys taking
precedence. New objects use `ae_stripe_*`. An unresolved checkout retry preserves
its original payload and key; subscription retries also retain immutable payloads.
The old `/extensions/stripe-saved/setup-agreement` return URL also remains an
authenticated alias for existing Stripe redirects. Reload old browser forms before
retrying consent. Old PHP namespaces are not registered. Verify setup, consent and
subscription settlement before resuming writers.

Remove the old extension directory only after success. If your deployment restores
extensions from saved ZIPs, archive the old ZIP privately outside that restore
directory and save `AeStripe.zip` there before restarting. Never install a second
gateway to perform this conversion.
