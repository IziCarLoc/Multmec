<?php

use App\Http\Controllers\HealthCheckController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::livewire('/acessar', 'pages::guest.login')->name('login');
});

Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::post('/sair', function (Request $request) {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');

    Route::livewire('/', 'pages::auth.dashboard')->name('dashboard');
    Route::livewire('/minha-senha', 'pages::auth.profile')->name('profile');

    Route::middleware('can:gestor')->group(function () {
        Route::livewire('/ajustes', 'pages::auth.settings.index')->name('settings');
        Route::livewire('/ajustes/usuarios', 'pages::auth.settings.users')->name('settings.users');
        Route::livewire('/ajustes/saude', 'pages::auth.settings.health')->name('settings.health');
        // Teste da hospedagem: PDF com 30 fotos geradas na hora (memória e tempo do dompdf no servidor).
        Route::get('/ajustes/saude/pdf-de-teste', [HealthCheckController::class, 'pdf'])->name('settings.health.pdf');
    });
});
