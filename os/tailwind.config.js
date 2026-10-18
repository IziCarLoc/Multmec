/** @type {import('tailwindcss').Config} */
// CSS compilado (public/css/app.css) e commitado: o servidor não tem Node. Depois de mexer em classes
// nas views ou em app/: `npm run build:css` (com o `composer install` feito, por causa das views de
// paginação do vendor) e commitar o CSS; o CI confere que o arquivo está atualizado.
module.exports = {
    content: [
        './resources/views/**/*.blade.php',
        './app/**/*.php',
        './vendor/livewire/livewire/src/Features/SupportPagination/views/*.blade.php',
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/tailwind.blade.php',
    ],
    darkMode: 'class',
    theme: {
        extend: {
            // Identidade do v12: azul do logo (cor de destaque), vermelho do anel (faixa do cabeçalho e
            // alertas), grafite da placa MULTMEC (cabeçalho).
            colors: {
                brand: {
                    50: '#eef7fd',
                    100: '#d6ebf9',
                    200: '#aed7f3',
                    300: '#7cbde9',
                    400: '#3fb0ec',
                    500: '#0f8bd8',
                    600: '#0874bd',
                    700: '#075e99',
                    800: '#0a4e7d',
                    900: '#0d3f63',
                },
                stripe: { DEFAULT: '#d71920', dark: '#ef4a4f' },
                plate: { DEFAULT: '#1d2328', 2: '#2c343b', ink: '#f4f7f9', muted: '#a9b6c0' },
            },
            fontFamily: {
                display: ['"Barlow Condensed"', '"Arial Narrow"', 'Arial', 'sans-serif'],
                sans: ['Barlow', 'system-ui', '-apple-system', '"Segoe UI"', 'sans-serif'],
                mono: ['"JetBrains Mono"', 'ui-monospace', 'Menlo', 'Consolas', 'monospace'],
            },
        },
    },
    plugins: [],
};
