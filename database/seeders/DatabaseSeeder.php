<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Super admin (troque a senha em produção via SEED_ADMIN_PASSWORD)
        User::query()->updateOrCreate(
            ['email' => 'admin@percapital.com.br'],
            [
                'name' => 'Administrador Percapital',
                'password' => env('SEED_ADMIN_PASSWORD', 'password'),
                'role' => User::ROLE_SUPER_ADMIN,
                'company_id' => null,
            ],
        );

        // Empresas de exemplo (idempotente)
        foreach ([
            ['name' => 'NovaBank', 'domain' => 'novabank.com.br', 'primary_color' => '#1437C9', 'secondary_color' => '#FF7A1A'],
            ['name' => 'Verde Pay', 'domain' => 'verdepay.com.br', 'primary_color' => '#0B8A5C', 'secondary_color' => '#FFC53D'],
        ] as $company) {
            Company::query()->updateOrCreate(
                ['domain' => $company['domain']],
                $company,
            );
        }
    }
}
