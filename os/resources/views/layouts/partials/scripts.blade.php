<script>
    function toggleTheme() {
        const dark = ! document.documentElement.classList.contains('dark');
        document.documentElement.classList.toggle('dark', dark);
        localStorage.setItem('theme', dark ? 'dark' : 'light');
    }

    // Dinheiro digitado como se escreve: "100" = 100,00; "100,5" = 100,50; "1.234,56"; "1.234" = 1234,00;
    // "12.5" = 12,50. Mesma regra de App\Support\Money::parseBr no servidor.
    window.moneyBr = {
        parse(text) {
            let t = String(text ?? '').replace(/R\$|\s| /g, '').replace(/[^\d.,-]/g, '')
            if (t === '' || t === '-' || !/\d/.test(t)) return null
            const negative = t.startsWith('-')
            t = t.replace(/^-+/, '')
            let int, dec
            if (t.includes(',')) {
                [int, dec = ''] = t.replace(/\./g, '').split(',')
            } else if (/^\d{1,3}(\.\d{3})+$/.test(t)) {
                int = t.replace(/\./g, ''); dec = ''
            } else if (/^(\d*)\.(\d{1,2})$/.test(t)) {
                [, int, dec] = t.match(/^(\d*)\.(\d{1,2})$/)
            } else {
                int = t.replace(/\./g, ''); dec = ''
            }
            int = int.replace(/\D/g, '') || '0'
            dec = (dec.replace(/\D/g, '') + '00').slice(0, 2)
            const cents = parseInt(int, 10) * 100 + parseInt(dec, 10)
            return negative ? -cents : cents
        },
        format(cents) {
            if (cents === null || cents === '' || cents === undefined || isNaN(cents)) return ''
            return (cents / 100).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
        },
        sanitize(text) { return String(text ?? '').replace(/[^\d.,-]/g, '') },
    }

    // CPF/CNPJ: máscara pelo tamanho (11 dígitos = CPF, 14 = CNPJ). Uso: componente Blade x-document-input (model="campo").
    window.docMask = {
        format(text) {
            const d = String(text ?? '').replace(/\D/g, '').slice(0, 14)
            return d.length <= 11
                ? d.replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d{1,2})$/, '$1-$2')
                : d.replace(/^(\d{2})(\d)/, '$1.$2').replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3').replace(/\.(\d{3})(\d)/, '.$1/$2').replace(/(\d{4})(\d)/, '$1-$2')
        },
    }

    // SHA-256 de um arquivo no próprio aparelho (só funciona em https ou em localhost). O servidor recalcula e
    // compara: arquivo que chega diferente do que saiu do celular é recusado.
    window.sha256File = async function (file) {
        if (! (window.crypto && crypto.subtle)) return null
        const digest = await crypto.subtle.digest('SHA-256', await file.arrayBuffer())
        return Array.from(new Uint8Array(digest)).map((b) => b.toString(16).padStart(2, '0')).join('')
    }

    document.addEventListener('alpine:init', () => {
        // Campo de dinheiro em centavos: x-data="moneyInput($wire.entangle('campo'))". O texto fica como a pessoa
        // digitou até sair do campo, quando vira 1.234,56; o valor em centavos acompanha cada tecla.
        Alpine.data('moneyInput', (cents, empty = null) => ({
            cents,
            text: '',
            focused: false,
            init() {
                this.text = window.moneyBr.format(this.cents)
                this.$watch('cents', (value) => { if (! this.focused) this.text = window.moneyBr.format(value) })
            },
            onInput(e) {
                this.text = window.moneyBr.sanitize(e.target.value)
                e.target.value = this.text
                const parsed = window.moneyBr.parse(this.text)
                this.cents = parsed === null ? empty : parsed
            },
            onFocus() { this.focused = true },
            onBlur(e) {
                this.focused = false
                this.text = window.moneyBr.format(this.cents)
                e.target.value = this.text
            },
        }))

        Alpine.data('docMask', (property) => ({
            init() {
                const current = property ? this.$wire.get(property) : null
                if (current) this.$el.value = window.docMask.format(current)
            },
            mask(e) {
                e.target.value = window.docMask.format(e.target.value)
                if (property) this.$wire.set(property, e.target.value, false)
            },
        }))
    })

    document.addEventListener('livewire:init', () => {
        // Barra de progresso: todo pedido do Livewire (clique, formulário) acende a barra até a resposta.
        const bar = document.getElementById('lw-progress')
        let inFlight = 0, timer = null
        const start = () => {
            if (++inFlight > 1 || ! bar) return
            clearTimeout(timer)
            bar.style.opacity = '1'
            bar.style.width = '20%'
            timer = setTimeout(() => { bar.style.width = '75%' }, 200)
        }
        const done = () => {
            inFlight = Math.max(0, inFlight - 1)
            if (inFlight > 0 || ! bar) return
            clearTimeout(timer)
            bar.style.width = '100%'
            timer = setTimeout(() => { bar.style.opacity = '0'; setTimeout(() => { bar.style.width = '0' }, 300) }, 150)
        }

        // Sessão expirada (app aberto no celular por dias) ou servidor sem resposta: aviso em português no lugar
        // da janela de erro do Livewire. O que já foi salvo continua no servidor.
        Livewire.hook('request', ({ succeed, fail }) => {
            start()
            succeed(done)
            fail(({ status, preventDefault }) => {
                done()
                if (status === 419) {
                    preventDefault()
                    window.dispatchEvent(new CustomEvent('toast', { detail: { message: 'Sua sessão expirou. A página vai recarregar; o que já foi salvo continua aqui.', type: 'error' } }))
                    setTimeout(() => window.location.reload(), 2500)
                } else if (status >= 500 || status === 0) {
                    preventDefault()
                    window.dispatchEvent(new CustomEvent('toast', { detail: { message: 'O servidor não respondeu. Tente de novo em instantes; o que já foi salvo não se perde.', type: 'error' } }))
                }
            })
        })
    })
</script>
