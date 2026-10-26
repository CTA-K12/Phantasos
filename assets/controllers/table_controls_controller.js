import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['search', 'perPage', 'tbody', 'pagination', 'info', 'cardItem']

    connect() {
        this.allRows = this.hasTbodyTarget
            ? Array.from(this.tbodyTarget.querySelectorAll('tr'))
            : []
        this.currentPage = 1
        this.query = ''
        this.perPage = this.hasPerPageTarget ? (parseInt(this.perPageTarget.value) || 10) : 10
        this.render()
    }

    search() {
        this.query = this.searchTarget.value.toLowerCase().trim()
        this.currentPage = 1
        this.render()
    }

    changePerPage() {
        this.perPage = parseInt(this.perPageTarget.value)
        this.currentPage = 1
        this.render()
    }

    goToPage(event) {
        event.preventDefault()
        this.currentPage = parseInt(event.currentTarget.dataset.page)
        this.render()
    }

    get filteredRows() {
        if (!this.query) return this.allRows
        return this.allRows.filter(row =>
            row.textContent.toLowerCase().includes(this.query)
        )
    }

    render() {
        // Filter card items if present
        if (this.hasCardItemTarget) {
            this.cardItemTargets.forEach(card => {
                const matches = !this.query || card.textContent.toLowerCase().includes(this.query)
                card.style.display = matches ? '' : 'none'
            })
        }

        const filtered = this.filteredRows
        const total = filtered.length
        const totalPages = Math.max(1, Math.ceil(total / this.perPage))

        // Clamp current page
        if (this.currentPage > totalPages) this.currentPage = totalPages

        const start = (this.currentPage - 1) * this.perPage
        const end = Math.min(start + this.perPage, total)

        // Show/hide rows
        this.allRows.forEach(row => (row.style.display = 'none'))
        filtered.slice(start, end).forEach(row => (row.style.display = ''))

        // Info text
        if (this.hasInfoTarget) {
            this.infoTarget.textContent = total === 0
                ? 'No records found'
                : `Showing ${start + 1}–${end} of ${total} record${total !== 1 ? 's' : ''}`
        }

        // Pagination
        if (this.hasPaginationTarget) {
            this.renderPagination(totalPages)
        }
    }

    renderPagination(totalPages) {
        if (totalPages <= 1) {
            this.paginationTarget.innerHTML = ''
            return
        }

        const current = this.currentPage
        const pages = this.pageRange(current, totalPages)

        const items = pages.map(p => {
            if (p === '…') {
                return `<li class="page-item disabled"><span class="page-link">…</span></li>`
            }
            const active = p === current ? 'active' : ''
            return `<li class="page-item ${active}">
                        <a class="page-link" href="#" data-page="${p}" data-action="click->table-controls#goToPage">${p}</a>
                    </li>`
        })

        const prev = current > 1
            ? `<li class="page-item"><a class="page-link" href="#" data-page="${current - 1}" data-action="click->table-controls#goToPage">‹</a></li>`
            : `<li class="page-item disabled"><span class="page-link">‹</span></li>`

        const next = current < totalPages
            ? `<li class="page-item"><a class="page-link" href="#" data-page="${current + 1}" data-action="click->table-controls#goToPage">›</a></li>`
            : `<li class="page-item disabled"><span class="page-link">›</span></li>`

        this.paginationTarget.innerHTML =
            `<ul class="pagination pagination-sm mb-0">${prev}${items.join('')}${next}</ul>`
    }

    // Produce a compact page range with ellipsis e.g. [1, 2, '…', 8, 9, 10]
    pageRange(current, total) {
        if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1)

        const pages = new Set([1, 2, current - 1, current, current + 1, total - 1, total])
        const sorted = [...pages].filter(p => p >= 1 && p <= total).sort((a, b) => a - b)

        const result = []
        let prev = null
        for (const p of sorted) {
            if (prev !== null && p - prev > 1) result.push('…')
            result.push(p)
            prev = p
        }
        return result
    }
}
