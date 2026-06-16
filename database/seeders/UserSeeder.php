<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Admin/Staff Fakultas Sains dan Teknologi UINAM (Verifikator)
        User::create([
            'name'              => 'Admin Fakultas FST',
            'email'             => 'admin.fst@uin-alauddin.ac.id',
            'organization_name' => null,
            'role'              => 'admin',
            'password'          => Hash::make('admin12345'),
        ]);

        // FR-4.1: Akun Keuangan Lantai 2 (untuk pencairan dana)
        User::create([
            'name'              => 'Keuangan Lt.2 FST',
            'email'             => 'keuangan.fst@uin-alauddin.ac.id',
            'organization_name' => null,
            'role'              => 'keuangan',
            'password'          => Hash::make('keuangan12345'),
        ]);

        // 11 Organisasi FST UINAM
        // Password default: Password@123 - wajib diganti setelah login pertama
        $organizations = [
            [
                'name'              => 'HMJTI FST UINAM',
                'email'             => 'hmjti.fst@uin-alauddin.ac.id',
                'organization_name' => 'Himpunan Mahasiswa Jurusan Teknik Informatika FST UINAM',
            ],
            [
                'name'              => 'HMJTA UINAM',
                'email'             => 'hmjta.fst@uin-alauddin.ac.id',
                'organization_name' => 'Himpunan Mahasiswa Jurusan Teknik Arsitektur UINAM',
            ],
            [
                'name'              => 'HMJ-Biologi FST UINAM',
                'email'             => 'hmj.biologi.fst@uin-alauddin.ac.id',
                'organization_name' => 'Himpunan Mahasiswa Jurusan Biologi FST UINAM',
            ],
            [
                'name'              => 'HMJ-Fisika FST UINAM',
                'email'             => 'hmj.fisika.fst@uin-alauddin.ac.id',
                'organization_name' => 'Himpunan Mahasiswa Jurusan Fisika FST UINAM',
            ],
            [
                'name'              => 'HMJ-Kimia FST UINAM',
                'email'             => 'hmj.kimia.fst@uin-alauddin.ac.id',
                'organization_name' => 'Himpunan Mahasiswa Jurusan Kimia FST UINAM',
            ],
            [
                'name'              => 'HMJ-MTK FST UINAM',
                'email'             => 'hmj.mtk.fst@uin-alauddin.ac.id',
                'organization_name' => 'Himpunan Mahasiswa Jurusan Matematika FST UINAM',
            ],
            [
                'name'              => 'HMJ-IP FST UINAM',
                'email'             => 'hmj.ip.fst@uin-alauddin.ac.id',
                'organization_name' => 'Himpunan Mahasiswa Jurusan Ilmu Peternakan FST UINAM',
            ],
            [
                'name'              => 'HMJ-T.PWK FST UINAM',
                'email'             => 'hmj.pwk.fst@uin-alauddin.ac.id',
                'organization_name' => 'Himpunan Mahasiswa Jurusan Teknik Perencanaan Wilayah dan Kota FST UINAM',
            ],
            [
                'name'              => 'HMJ-SI FST UINAM',
                'email'             => 'hmj.si.fst@uin-alauddin.ac.id',
                'organization_name' => 'Himpunan Mahasiswa Jurusan Sistem Informasi FST UINAM',
            ],
            [
                'name'              => 'DEMA FST UINAM',
                'email'             => 'dema.fst@uin-alauddin.ac.id',
                'organization_name' => 'Dewan Eksekutif Mahasiswa Fakultas Sains dan Teknologi UINAM',
            ],
            [
                'name'              => 'SEMA FST UINAM',
                'email'             => 'sema.fst@uin-alauddin.ac.id',
                'organization_name' => 'Senat Mahasiswa Fakultas Sains dan Teknologi UINAM',
            ],
        ];

        foreach ($organizations as $org) {
            User::create(array_merge($org, [
                'role'     => 'organization',
                'password' => Hash::make('Password@123'),
            ]));
        }
    }
}
