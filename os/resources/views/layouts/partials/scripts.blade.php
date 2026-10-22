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

    // Reduz a foto no próprio aparelho (lado maior = maxSide, JPEG) antes de enviar: chega rápido mesmo com sinal
    // fraco. Formato que o navegador não lê (alguns HEIC) vai como está e o servidor reduz.
    window.downscalePhoto = async function (file, maxSide = 1600) {
        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' })
        const scale = Math.min(1, maxSide / Math.max(bitmap.width, bitmap.height))
        const w = Math.max(1, Math.round(bitmap.width * scale))
        const h = Math.max(1, Math.round(bitmap.height * scale))
        const canvas = document.createElement('canvas')
        canvas.width = w
        canvas.height = h
        canvas.getContext('2d').drawImage(bitmap, 0, 0, w, h)
        bitmap.close && bitmap.close()
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.85))
        if (! blob) throw new Error('canvas')
        return new File([blob], 'foto.jpg', { type: 'image/jpeg', lastModified: file.lastModified })
    }

    window.toastError = (message) => window.dispatchEvent(new CustomEvent('toast', { detail: { message, type: 'error' } }))

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

        // Câmera do celular: reduz a foto, calcula o SHA-256, envia ($wire.upload) e manda o componente guardar
        // (`savePhoto(slot, hash, horaDaFoto)`). O servidor confere o hash e carimba com a hora dele.
        Alpine.data('photoCapture', () => ({
            busy: null,
            async take(event, slot) {
                const original = event.target.files && event.target.files[0]
                event.target.value = ''
                if (! original) return
                this.busy = slot
                let file = original
                try { file = await window.downscalePhoto(original) } catch (e) { /* manda o original */ }
                const hash = await window.sha256File(file)
                if (! hash) {
                    this.busy = null
                    window.toastError('Este navegador não conferiu a foto. Abra o sistema pelo endereço https.')
                    return
                }
                this.$wire.upload('upload', file,
                    () => { this.$wire.savePhoto(slot, hash, original.lastModified || null).finally(() => { this.busy = null }) },
                    () => { this.busy = null; window.toastError('Não foi possível enviar a foto. Verifique a conexão e tente de novo.') },
                )
            },
        }))

        // Assinatura com o dedo num quadro; `save()` devolve o PNG (data URL) para o componente.
        Alpine.data('signaturePad', () => ({
            drawing: false,
            dirty: false,
            ctx: null,
            init() {
                const canvas = this.$refs.canvas
                const ratio = window.devicePixelRatio || 1
                const rect = canvas.getBoundingClientRect()
                canvas.width = Math.round(rect.width * ratio)
                canvas.height = Math.round(rect.height * ratio)
                this.ctx = canvas.getContext('2d')
                this.ctx.scale(ratio, ratio)
                this.ctx.lineWidth = 2.5
                this.ctx.lineCap = 'round'
                this.ctx.lineJoin = 'round'
                this.ctx.strokeStyle = '#0d1b26'
            },
            pos(event) {
                const rect = this.$refs.canvas.getBoundingClientRect()
                return { x: event.clientX - rect.left, y: event.clientY - rect.top }
            },
            start(event) {
                this.drawing = true
                const p = this.pos(event)
                this.ctx.beginPath()
                this.ctx.moveTo(p.x, p.y)
                this.ctx.lineTo(p.x + 0.1, p.y + 0.1)
                this.ctx.stroke()
                this.dirty = true
            },
            move(event) {
                if (! this.drawing) return
                const p = this.pos(event)
                this.ctx.lineTo(p.x, p.y)
                this.ctx.stroke()
            },
            end() { this.drawing = false },
            clear() {
                const canvas = this.$refs.canvas
                this.ctx.save()
                this.ctx.setTransform(1, 0, 0, 1, 0, 0)
                this.ctx.clearRect(0, 0, canvas.width, canvas.height)
                this.ctx.restore()
                this.dirty = false
            },
            dataUrl() { return this.dirty ? this.$refs.canvas.toDataURL('image/png') : null },
        }))

        // Marcas de avaria: toque na foto põe uma bolinha numerada; tipo e descrição de cada uma; "Salvar"
        // manda a lista inteira (x e y de 0 a 1 em relação à foto carimbada).
        Alpine.data('damageMarker', (initial, types) => ({
            marks: JSON.parse(JSON.stringify(initial || [])),
            types,
            place(event) {
                const rect = this.$refs.img.getBoundingClientRect()
                const x = Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width))
                const y = Math.min(1, Math.max(0, (event.clientY - rect.top) / rect.height))
                this.marks.push({ x: Number(x.toFixed(4)), y: Number(y.toFixed(4)), type: 'Risco', desc: '' })
            },
            remove(index) { this.marks.splice(index, 1) },
        }))

        // Enviar a vistoria pelo WhatsApp em dois toques: "Preparar" (o servidor gera e guarda o PDF) e "Enviar"
        // (abre o compartilhar do celular com o PDF). Sem compartilhar arquivo (computador), baixa o PDF e abre o
        // WhatsApp com a mensagem, e a pessoa confirma que enviou.
        Alpine.data('shareDocument', () => ({
            file: null,
            text: '',
            link: null,
            busy: false,
            async prepare() {
                this.busy = true
                try {
                    const res = await this.$wire.preparePdf()
                    if (! res) return
                    const bytes = Uint8Array.from(atob(res.b64), (c) => c.charCodeAt(0))
                    this.file = new File([bytes], res.name, { type: 'application/pdf' })
                    this.text = res.text
                    this.link = res.link
                } finally {
                    this.busy = false
                }
            },
            canShareFile() {
                return !! (this.file && navigator.canShare && navigator.canShare({ files: [this.file] }))
            },
            async send() {
                if (! this.file) return
                if (this.canShareFile()) {
                    try {
                        await navigator.share({ files: [this.file], text: this.text })
                        await this.$wire.confirmSent('compartilhar')
                        this.file = null
                    } catch (e) {
                        if (! e || e.name !== 'AbortError') window.toastError('Não deu para abrir o compartilhar. Use "Baixar PDF" e envie pelo WhatsApp.')
                    }
                    return
                }
                const a = document.createElement('a')
                a.href = URL.createObjectURL(this.file)
                a.download = this.file.name
                a.click()
                if (this.link) window.open(this.link, '_blank', 'noopener')
                this.file = null
                this.$wire.set('askSentConfirmation', true)
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
