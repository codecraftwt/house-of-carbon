<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RoleAndUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            'Admin',
            'Customer',
            'Supplier',
            'CHA',
            'Back Office',
        ];

        foreach ($roles as $roleName) {
            \App\Models\Role::firstOrCreate(['name' => $roleName]);
        }

        $users = [
            [
                'name' => 'Admin User',
                'email' => 'admin@houseofcarbon.com',
                'password' => \Illuminate\Support\Facades\Hash::make('admin123'),
                'role' => 'Admin',
            ],
            [
                'name' => 'Customer User',
                'email' => 'customer@example.com',
                'password' => \Illuminate\Support\Facades\Hash::make('customer123'),
                'role' => 'Customer',
            ],
            [
                'name' => 'Supplier User',
                'email' => 'supplier@example.com',
                'password' => \Illuminate\Support\Facades\Hash::make('supplier123'),
                'role' => 'Supplier',
            ],
            [
                'name' => 'CHA User',
                'email' => 'cha@example.com',
                'password' => \Illuminate\Support\Facades\Hash::make('cha123'),
                'role' => 'CHA',
            ],
            [
                'name' => 'Back Office User',
                'email' => 'backoffice@houseofcarbon.com',
                'password' => \Illuminate\Support\Facades\Hash::make('backoffice123'),
                'role' => 'Back Office',
            ],
        ];

        foreach ($users as $userData) {
            $role = \App\Models\Role::where('name', $userData['role'])->first();
            \App\Models\User::firstOrCreate(
                ['email' => $userData['email']],
                [
                    'name' => $userData['name'],
                    'password' => $userData['password'],
                    'role_id' => $role->id,
                ]
            );
        }
    }
}
