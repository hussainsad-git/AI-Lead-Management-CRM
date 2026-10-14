<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class InstallCommand extends Command
{
    protected $signature = 'leadflow:install
        {--org= : Organization name} {--timezone=Asia/Karachi} {--currency=PKR} {--country=PK}
        {--name= : Super admin name} {--email= : Super admin email}';

    protected $description = 'Create an organization and its first Super Administrator';

    public function handle(OrganizationProvisioner $provisioner): int
    {
        // A release archive may omit empty runtime directories; create them so view/config caching works.
        foreach (['storage/app/private', 'storage/logs', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/framework/testing', 'bootstrap/cache'] as $dir) {
            if (! is_dir(base_path($dir))) {
                @mkdir(base_path($dir), 0775, true);
                $this->line("Created $dir");
            }
        }
        $org = $this->option('org') ?: $this->ask('Organization name');
        $name = $this->option('name') ?: $this->ask('Administrator name');
        $email = $this->option('email') ?: $this->ask('Administrator email');
        $password = $this->secret('Administrator password (min 10 chars, letters and numbers)');

        $v = Validator::make(compact('org', 'name', 'email', 'password') + ['timezone' => $this->option('timezone'), 'currency' => $this->option('currency')], [
            'org' => ['required', 'string', 'max:160'], 'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'unique:users,email'], 'password' => ['required', Password::min(10)->letters()->numbers()],
            'timezone' => ['required', 'timezone:all'], 'currency' => ['required', 'size:3'],
        ]);
        if ($v->fails()) {
            foreach ($v->errors()->all() as $e) {
                $this->error($e);
            }

            return self::FAILURE;
        }

        $organization = $provisioner->create($org, $this->option('timezone'), strtoupper($this->option('currency')), strtoupper($this->option('country')));
        app(\App\Support\Tenancy\CurrentOrganization::class)->runAs($organization, function () use ($organization, $name, $email, $password) {
            $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
            $user->organization_id = $organization->id;
            $user->is_platform_admin = ! User::where('is_platform_admin', true)->exists(); // first installer operates the platform
            $user->save();
            $user->roles()->sync([Role::where('key', 'super_admin')->value('id')]);
        });
        $this->info("Organization \"{$organization->name}\" created. Sign in as {$email}.");

        return self::SUCCESS;
    }
}
