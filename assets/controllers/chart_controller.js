import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        type: { type: String, default: 'bar' },
        dataUrl: { type: String, default: '' },
        dataInline: { type: String, default: '' },
        chartKey: String,
        height: { type: Number, default: 320 },
        eventDate: { type: String, default: '' },
        rowHover: { type: Boolean, default: false },
    };

    async connect() {
        const ApexCharts = (await import('apexcharts')).default;
        this.ApexCharts = ApexCharts;

        this.refreshHandler = (e) => this.refresh(e);
        this.element.addEventListener('chart:refresh', this.refreshHandler);

        if (this.dataInlineValue) {
            await this.loadChartInline();
        } else {
            await this.loadChart();
        }
    }

    disconnect() {
        this.element.removeEventListener('chart:refresh', this.refreshHandler);
        if (this._hoverCleanup) {
            this._hoverCleanup();
            this._hoverCleanup = null;
        }
        if (this.chart) {
            this.chart.destroy();
            this.chart = null;
        }
    }

    async loadChartInline() {
        const data = JSON.parse(this.dataInlineValue);
        const chartData = this.chartKeyValue ? data[this.chartKeyValue] : data;

        if (this._hoverCleanup) {
            this._hoverCleanup();
            this._hoverCleanup = null;
        }
        if (this.chart) {
            this.chart.destroy();
        }

        const options = this.buildOptions(chartData);
        this.chart = new this.ApexCharts(this.element, options);
        await this.chart.render();
    }

    async loadChart(params = '') {
        const url = params ? `${this.dataUrlValue}?${params}` : this.dataUrlValue;
        const response = await fetch(url);
        const data = await response.json();

        const chartData = this.chartKeyValue ? data[this.chartKeyValue] : data;

        if (this._hoverCleanup) {
            this._hoverCleanup();
            this._hoverCleanup = null;
        }

        if (this.chart) {
            this.chart.destroy();
        }

        const options = this.buildOptions(chartData);
        this.chart = new this.ApexCharts(this.element, options);
        await this.chart.render();

        if (this.rowHoverValue || this.chartKeyValue === 'byStudent' || this.chartKeyValue === 'byTimeBlock') {
            this._attachRowHover();
        }
        if (this.typeValue === 'donut') {
            this._attachDonutHover();
        }
    }

    _attachRowHover() {
        const container = this.element;
        let currentJ = null;
        let rafId = null;

        const setRowOpacity = (j) => {
            if (currentJ === j) return;
            currentJ = j;
            container.querySelectorAll('.apexcharts-bar-area').forEach(bar => {
                bar.style.opacity = (j === null) ? '' : (bar.getAttribute('j') == j ? '1' : '0.6');
            });
        };

        const onMouseMove = (e) => {
            if (rafId) return;
            rafId = requestAnimationFrame(() => {
                rafId = null;
                const mx = e.clientX, my = e.clientY;
                for (const bar of container.querySelectorAll('.apexcharts-bar-area')) {
                    const r = bar.getBoundingClientRect();
                    if (mx >= r.left && mx <= r.right && my >= r.top && my <= r.bottom) {
                        setRowOpacity(bar.getAttribute('j'));
                        return;
                    }
                }
                setRowOpacity(null);
            });
        };

        const onMouseLeave = () => {
            if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
            setRowOpacity(null);
            currentJ = null;
        };

        container.addEventListener('mousemove', onMouseMove);
        container.addEventListener('mouseleave', onMouseLeave);

        this._hoverCleanup = () => {
            container.removeEventListener('mousemove', onMouseMove);
            container.removeEventListener('mouseleave', onMouseLeave);
            if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
        };
    }

    _attachDonutHover() {
        const container = this.element;

        const setSliceOpacity = (activeSlice) => {
            container.querySelectorAll('.apexcharts-pie-area').forEach(slice => {
                slice.style.opacity = (activeSlice === null || slice === activeSlice) ? '1' : '0.55';
            });
        };

        // Start all slices muted; wait a tick for ApexCharts to finish rendering
        const applyInitialMute = () => {
            container.querySelectorAll('.apexcharts-pie-area').forEach(slice => {
                slice.style.opacity = '0.7';
            });
        };
        setTimeout(applyInitialMute, 50);

        const onMouseOver = (e) => {
            const slice = e.target.closest('.apexcharts-pie-area');
            if (slice) setSliceOpacity(slice);
        };

        const onMouseLeave = () => {
            container.querySelectorAll('.apexcharts-pie-area').forEach(slice => {
                slice.style.opacity = '0.7';
            });
        };

        container.addEventListener('mouseover', onMouseOver);
        container.addEventListener('mouseleave', onMouseLeave);

        this._hoverCleanup = () => {
            container.removeEventListener('mouseover', onMouseOver);
            container.removeEventListener('mouseleave', onMouseLeave);
        };
    }

    buildOptions(data) {
        const type = this.typeValue;

        if (type === 'bar' && this.chartKeyValue === 'byStudent') {
            const { students = [], series = [] } = data;
            const height = Math.max(280, students.length * 28 + 60);
            return {
                chart: {
                    type: 'bar', height, stacked: true, toolbar: { show: false },
                },
                series: series.map(s => ({ name: s.name, data: s.data })),
                colors: series.map(s => s.color),
                states: {
                    hover: { filter: { type: 'none' } },
                    active: { filter: { type: 'none' } },
                },
                xaxis: { categories: students },
                plotOptions: { bar: { horizontal: true, borderRadius: 0 } },
                legend: { position: 'top' },
                dataLabels: { enabled: false },
                tooltip: {
                    shared: true,
                    intersect: false,
                    y: { formatter: val => val + (val === 1 ? ' incident' : ' incidents') },
                },
            };
        }

        if (type === 'bar' && this.chartKeyValue === 'byTimeBlock') {
            const { timeBlocks = [], series = [] } = data;
            const height = Math.max(280, timeBlocks.length * 18 + 60);
            return {
                chart: { type: 'bar', height, stacked: true, toolbar: { show: false } },
                series: series.map(s => ({ name: s.name, data: s.data })),
                colors: series.map(s => s.color),
                states: {
                    hover: { filter: { type: 'none' } },
                    active: { filter: { type: 'none' } },
                },
                xaxis: { categories: timeBlocks.map(tb => this.formatTimeBlock(tb)) },
                plotOptions: { bar: { horizontal: true, borderRadius: 0 } },
                legend: { position: 'top' },
                dataLabels: { enabled: false },
                tooltip: {
                    shared: true,
                    intersect: false,
                    y: { formatter: val => val + (val === 1 ? ' incident' : ' incidents') },
                },
            };
        }

        if (type === 'hbar') {
            // Generic horizontal bar chart: expects [{label, count, color}]
            // Uses distributed colors so each bar gets its own color from the data.
            const height = Math.max(200, data.length * 40 + 60);
            return {
                chart: { type: 'bar', height, toolbar: { show: false } },
                series: [{ name: 'Count', data: data.map(d => parseInt(d.count)) }],
                colors: data.map(d => d.color || '#7a5af8'),
                states: {
                    hover: { filter: { type: 'none' } },
                    active: { filter: { type: 'none' } },
                },
                xaxis: { categories: data.map(d => d.label) },
                plotOptions: { bar: { horizontal: true, borderRadius: 0, distributed: true } },
                legend: { show: false },
                dataLabels: { enabled: false },
                tooltip: {
                    y: { formatter: val => val + (val === 1 ? ' item' : ' items') },
                },
            };
        }

        if (type === 'donut') {
            return {
                chart: { type: 'donut', height: this.heightValue },
                series: data.map(d => parseInt(d.count)),
                labels: data.map(d => d.label),
                colors: data.map(d => d.color),
                legend: { position: 'top' },
                plotOptions: { pie: { expandOnClick: false } },
                states: {
                    hover: { filter: { type: 'none' } },
                    active: { filter: { type: 'none' } },
                },
            };
        }

        if (type === 'line') {
            const options = {
                chart: { type: 'line', height: this.heightValue, toolbar: { show: false } },
                series: [{ name: 'Incidents', data: data.map(d => parseInt(d.count)) }],
                xaxis: {
                    categories: data.map(d => d.date),
                    labels: { rotate: -45 },
                },
                colors: ['#66bb6a'],
                stroke: { curve: 'smooth', width: 2 },
                markers: { size: 4 },
            };

            if (this.eventDateValue) {
                options.annotations = {
                    xaxis: [{
                        x: this.eventDateValue,
                        borderColor: '#7a5af8',
                        label: {
                            text: 'Event',
                            style: { color: '#fff', background: '#7a5af8', fontSize: '11px' },
                        },
                    }],
                };
            }

            return options;
        }

        // Default bar
        return {
            chart: { type: 'bar', height: this.heightValue, toolbar: { show: false } },
            series: [{ name: 'Count', data: data.map(d => parseInt(d.count || 0)) }],
            xaxis: { categories: data.map(d => d.name || d.label || '') },
            colors: ['#4a6fa5'],
        };
    }

    formatTimeBlock(block) {
        const [h, m] = block.split(':');
        const hour = parseInt(h);
        const period = hour >= 12 ? 'PM' : 'AM';
        const displayHour = hour > 12 ? hour - 12 : (hour === 0 ? 12 : hour);
        return `${displayHour}:${m} ${period}`;
    }

    async capture() {
        if (!this.chart) return null;
        const { imgURI } = await this.chart.dataURI();
        return imgURI;
    }

    // Called by filter_controller via custom event
    refresh(event) {
        const params = event.detail?.params || '';
        this.loadChart(params);
    }
}
