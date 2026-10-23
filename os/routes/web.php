<?php

use App\Http\Controllers\BudgetPdfController;
use App\Http\Controllers\HealthCheckController;
use App\Http\Controllers\InspectionPdfController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\MonthlyReportController;
use App\Http\Controllers\OrderPdfController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::livewire('/acessar', 'pages::guest.login')->name('login');
});

// Orçamento para o cliente responder sem login. Abrir não muda nada; só o botão grava a resposta.
Route::livewire('/orcamento/{token}', 'pages::public.budget')->where('token', '[A-Za-z0-9]{20,80}')->middleware('throttle:30,1')->name('budget.public');

Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::post('/sair', function (Request $request) {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');

    Route::livewire('/', 'pages::auth.orders.board')->name('dashboard');
    Route::livewire('/minha-senha', 'pages::auth.profile')->name('profile');
    Route::livewire('/avisos', 'pages::auth.inbox')->name('inbox');

    Route::livewire('/os', 'pages::auth.orders.index')->name('orders.index');
    Route::livewire('/os/nova', 'pages::auth.orders.create')->middleware('can:atendimento')->name('orders.create');
    Route::livewire('/os/{order}', 'pages::auth.orders.show')->whereNumber('order')->name('orders.show');
    Route::get('/os/{order}/vistoria.pdf', [InspectionPdfController::class, 'show'])->whereNumber('order')->name('orders.inspection.pdf');

    // Fotos e vídeos do disco privado: só com login.
    Route::get('/midia/{media}/{variant?}', [MediaController::class, 'show'])->whereNumber('media')->name('media.show');

    Route::middleware('can:atendimento')->group(function () {
        Route::livewire('/os/por-mes', 'pages::auth.orders.monthly')->name('orders.monthly');
        Route::get('/os/por-mes.pdf', [MonthlyReportController::class, 'show'])->name('orders.monthly.pdf');
        Route::get('/os/{order}/os.pdf', [OrderPdfController::class, 'show'])->whereNumber('order')->name('orders.pdf');
        Route::get('/os/{order}/orcamento-previa.pdf', [BudgetPdfController::class, 'show'])->whereNumber('order')->name('orders.budget.preview');
        Route::get('/os/{order}/orcamento/{budget}.pdf', [BudgetPdfController::class, 'show'])->whereNumber(['order', 'budget'])->name('orders.budget.pdf');
        Route::livewire('/clientes-e-veiculos', 'pages::auth.registry.index')->name('registry');
        Route::livewire('/fornecedores', 'pages::auth.suppliers.index')->name('suppliers');

        // Financeiro: gestor e atendimento (o atendimento não vê lucro nem margem).
        Route::livewire('/financeiro', 'pages::auth.finance.dashboard')->name('finance');
        Route::livewire('/financeiro/receber', 'pages::auth.finance.receivables')->name('finance.receivables');
        Route::livewire('/financeiro/pagar', 'pages::auth.finance.payables')->name('finance.payables');
        Route::livewire('/financeiro/inadimplentes', 'pages::auth.finance.delinquents')->name('finance.delinquents');
        Route::livewire('/financeiro/prejuizos', 'pages::auth.finance.losses')->name('finance.losses');
        Route::livewire('/financeiro/prestadores', 'pages::auth.finance.providers')->name('finance.providers');
        Route::livewire('/financeiro/contas-fixas', 'pages::auth.finance.recurring')->name('finance.recurring');
        Route::livewire('/financeiro/credito', 'pages::auth.finance.credit')->name('finance.credit');
        Route::livewire('/financeiro/relatorios', 'pages::auth.finance.reports')->name('finance.reports');
    });

    Route::middleware('can:gestor')->group(function () {
        Route::livewire('/ajustes', 'pages::auth.settings.index')->name('settings');
        Route::livewire('/ajustes/usuarios', 'pages::auth.settings.users')->name('settings.users');
        Route::livewire('/ajustes/saude', 'pages::auth.settings.health')->name('settings.health');
        Route::livewire('/ajustes/frota-izi', 'pages::auth.settings.izi-fleet')->name('settings.izi-fleet');
        Route::livewire('/ajustes/checklists', 'pages::auth.settings.checklists')->name('settings.checklists');
        Route::livewire('/ajustes/importar', 'pages::auth.settings.import')->name('settings.import');
        // Financeiro só do gestor: a Meta do mês (lucro, retirada) e a importação da planilha.
        Route::livewire('/financeiro/meta', 'pages::auth.finance.goal')->name('finance.goal');
        Route::livewire('/financeiro/importar-planilha', 'pages::auth.finance.spreadsheet-import')->name('finance.spreadsheet-import');
        // Teste da hospedagem: PDF com 30 fotos geradas na hora (memória e tempo do dompdf no servidor).
        Route::get('/ajustes/saude/pdf-de-teste', [HealthCheckController::class, 'pdf'])->name('settings.health.pdf');
    });
});
