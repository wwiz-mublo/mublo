/**
 * VisitorStats — Canvas 차트 유틸리티
 *
 * 외부 라이브러리 없이 Canvas API로 라인/바/도넛 차트 렌더링
 */
var VisitorChart = (function () {
    'use strict';

    var colors = {
        primary: '#818cf8',
        success: '#34d399',
        warning: '#fbbf24',
        info:    '#38bdf8',
        danger:  '#f87171',
        purple:  '#a78bfa',
        teal:    '#2dd4bf',
        pink:    '#f472b6',
        gray:    '#94a3b8',
        orange:  '#fb923c',
        lime:    '#a3e635',
    };

    var palette = [
        colors.primary, colors.success, colors.warning, colors.pink, colors.info,
        colors.purple, colors.orange, colors.teal, colors.danger, colors.lime,
    ];

    // =========================================================================
    // 라인 차트
    // =========================================================================
    function lineChart(canvasId, data, options) {
        var canvas = document.getElementById(canvasId);
        if (!canvas || !data || data.length === 0) return;

        var ctx = canvas.getContext('2d');
        var width = canvas.clientWidth || 800;
        var height = options.height || 200;
        canvas.width = width;
        canvas.height = height;

        var dpr = window.devicePixelRatio || 1;
        if (dpr > 1) {
            canvas.width = width * dpr;
            canvas.height = height * dpr;
            canvas.style.width = width + 'px';
            canvas.style.height = height + 'px';
            ctx.scale(dpr, dpr);
        }

        ctx.clearRect(0, 0, width, height);

        var padding = { top: 30, right: 16, bottom: 32, left: 50 };
        var plotW = width - padding.left - padding.right;
        var plotH = height - padding.top - padding.bottom;

        var series = options.series || [];
        var labels = data.map(function (d) { return d[options.labelKey || 'date']; });

        // 전체 최대값
        var maxVal = 1;
        series.forEach(function (s) {
            data.forEach(function (d) {
                var v = Number(d[s.key] || 0);
                if (v > maxVal) maxVal = v;
            });
        });
        maxVal = Math.ceil(maxVal * 1.1); // 10% 여유

        // 축
        drawGrid(ctx, padding, plotW, plotH, maxVal);

        // 라인
        series.forEach(function (s) {
            var vals = data.map(function (d) { return Number(d[s.key] || 0); });
            drawLine(ctx, vals, s.color || colors.primary, padding, plotW, plotH, maxVal, s.dash || null);
        });

        // X 라벨
        drawXLabels(ctx, labels, padding, plotW, plotH);

        // 범례
        drawLegend(ctx, series, padding.left, 10, plotW);
    }

    // =========================================================================
    // 바 차트
    // =========================================================================
    function barChart(canvasId, data, options) {
        var canvas = document.getElementById(canvasId);
        if (!canvas || !data || data.length === 0) return;

        var ctx = canvas.getContext('2d');
        var width = canvas.clientWidth || 800;
        var height = options.height || 160;
        canvas.width = width;
        canvas.height = height;

        var dpr = window.devicePixelRatio || 1;
        if (dpr > 1) {
            canvas.width = width * dpr;
            canvas.height = height * dpr;
            canvas.style.width = width + 'px';
            canvas.style.height = height + 'px';
            ctx.scale(dpr, dpr);
        }

        ctx.clearRect(0, 0, width, height);

        var padding = { top: 16, right: 16, bottom: 32, left: 50 };
        var plotW = width - padding.left - padding.right;
        var plotH = height - padding.top - padding.bottom;

        var vals = data.map(function (d) { return Number(d[options.valueKey || 'visitors'] || 0); });
        var labels = data.map(function (d) { return d[options.labelKey || 'hour']; });
        var maxVal = Math.max.apply(null, vals.concat([1]));
        maxVal = Math.ceil(maxVal * 1.1);

        drawGrid(ctx, padding, plotW, plotH, maxVal);

        var barWidth = Math.max(4, (plotW / vals.length) * 0.6);
        var gap = plotW / vals.length;
        var barColor = options.color || colors.primary;

        vals.forEach(function (v, i) {
            var barH = (v / maxVal) * plotH;
            var x = padding.left + gap * i + (gap - barWidth) / 2;
            var y = padding.top + plotH - barH;

            ctx.fillStyle = barColor;
            ctx.fillRect(x, y, barWidth, barH);
        });

        // X 라벨
        ctx.fillStyle = '#6c757d';
        ctx.font = '11px sans-serif';
        ctx.textAlign = 'center';
        labels.forEach(function (label, i) {
            var x = padding.left + gap * i + gap / 2;
            ctx.fillText(String(label), x, padding.top + plotH + 16);
        });
    }

    // =========================================================================
    // 도넛 차트
    // =========================================================================
    function donutChart(canvasId, data, options) {
        var canvas = document.getElementById(canvasId);
        if (!canvas || !data || data.length === 0) return;

        var ctx = canvas.getContext('2d');
        var size = options.size || 200;
        canvas.width = size;
        canvas.height = size;

        var dpr = window.devicePixelRatio || 1;
        if (dpr > 1) {
            canvas.width = size * dpr;
            canvas.height = size * dpr;
            canvas.style.width = size + 'px';
            canvas.style.height = size + 'px';
            ctx.scale(dpr, dpr);
        }

        ctx.clearRect(0, 0, size, size);

        var total = 0;
        data.forEach(function (d) { total += Number(d[options.valueKey || 'count'] || 0); });
        if (total === 0) {
            ctx.fillStyle = '#dee2e6';
            ctx.beginPath();
            ctx.arc(size / 2, size / 2, size / 2 - 10, 0, Math.PI * 2);
            ctx.fill();
            ctx.fillStyle = '#6c757d';
            ctx.font = '13px sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('데이터 없음', size / 2, size / 2 + 5);
            return;
        }

        var cx = size / 2;
        var cy = size / 2;
        var outerR = size / 2 - 6;
        var innerR = outerR * 0.55;
        var startAngle = -Math.PI / 2;

        data.forEach(function (d, i) {
            var val = Number(d[options.valueKey || 'count'] || 0);
            var sliceAngle = (val / total) * Math.PI * 2;
            var endAngle = startAngle + sliceAngle;

            var usePalette = options.palette || palette;
            ctx.fillStyle = usePalette[i % usePalette.length];
            ctx.beginPath();
            ctx.arc(cx, cy, outerR, startAngle, endAngle);
            ctx.arc(cx, cy, innerR, endAngle, startAngle, true);
            ctx.closePath();
            ctx.fill();

            startAngle = endAngle;
        });

        // 중앙 텍스트
        ctx.fillStyle = '#212529';
        ctx.font = 'bold 18px sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(formatNum(total), cx, cy);
    }

    /**
     * 도넛 차트 범례 HTML 생성
     */
    function donutLegend(data, options) {
        var valueKey = options.valueKey || 'count';
        var nameKey = options.nameKey || 'name';
        var total = 0;
        data.forEach(function (d) { total += Number(d[valueKey] || 0); });

        var html = '<div class="vs-legend">';
        data.forEach(function (d, i) {
            var val = Number(d[valueKey] || 0);
            var pct = total > 0 ? Math.round(val / total * 100) : 0;
            var color = palette[i % palette.length];
            html += '<div class="vs-legend-item">';
            html += '<span class="vs-legend-dot" style="background:' + color + '"></span>';
            html += '<span class="vs-legend-name">' + escapeHtml(d[nameKey] || '') + '</span>';
            html += '<span class="vs-legend-val">' + formatNum(val) + ' (' + pct + '%)</span>';
            html += '</div>';
        });
        html += '</div>';
        return html;
    }

    // =========================================================================
    // Private 유틸
    // =========================================================================

    function drawGrid(ctx, padding, plotW, plotH, maxVal) {
        ctx.strokeStyle = '#e9ecef';
        ctx.lineWidth = 1;

        var gridLines = 4;
        for (var i = 0; i <= gridLines; i++) {
            var y = padding.top + (plotH / gridLines) * i;
            ctx.beginPath();
            ctx.moveTo(padding.left, y);
            ctx.lineTo(padding.left + plotW, y);
            ctx.stroke();

            var label = formatNum(Math.round(maxVal * (1 - i / gridLines)));
            ctx.fillStyle = '#6c757d';
            ctx.font = '11px sans-serif';
            ctx.textAlign = 'right';
            ctx.fillText(label, padding.left - 8, y + 4);
        }
    }

    function drawLine(ctx, values, color, padding, plotW, plotH, maxVal, dash) {
        if (values.length === 0) return;

        ctx.save();
        ctx.strokeStyle = color;
        ctx.lineWidth = 2;
        if (dash) ctx.setLineDash(dash);
        ctx.beginPath();

        values.forEach(function (v, i) {
            var x = xAt(i, values.length, padding.left, plotW);
            var y = yAt(v, maxVal, padding.top, plotH);
            if (i === 0) ctx.moveTo(x, y);
            else ctx.lineTo(x, y);
        });
        ctx.stroke();
        ctx.restore();

        // 포인트 (점선일 때는 작은 포인트)
        var pointR = dash ? 2 : 3;
        values.forEach(function (v, i) {
            var x = xAt(i, values.length, padding.left, plotW);
            var y = yAt(v, maxVal, padding.top, plotH);
            ctx.fillStyle = color;
            ctx.beginPath();
            ctx.arc(x, y, pointR, 0, Math.PI * 2);
            ctx.fill();
        });
    }

    function drawXLabels(ctx, labels, padding, plotW, plotH) {
        ctx.fillStyle = '#6c757d';
        ctx.font = '11px sans-serif';
        ctx.textAlign = 'center';

        // 라벨이 너무 많으면 간격 조정
        var step = labels.length > 15 ? Math.ceil(labels.length / 10) : 1;
        labels.forEach(function (label, i) {
            if (i % step !== 0 && i !== labels.length - 1) return;
            var x = xAt(i, labels.length, padding.left, plotW);
            var display = String(label).length > 5 ? String(label).slice(5) : String(label);
            ctx.fillText(display, x, padding.top + plotH + 18);
        });
    }

    function drawLegend(ctx, series, startX, y, width) {
        ctx.font = '12px sans-serif';
        var x = startX;

        series.forEach(function (s) {
            ctx.fillStyle = s.color || colors.primary;
            ctx.fillRect(x, y, 14, 3);
            x += 18;

            ctx.fillStyle = '#212529';
            ctx.textAlign = 'left';
            ctx.fillText(s.label || s.key, x, y + 5);
            x += ctx.measureText(s.label || s.key).width + 16;
        });
    }

    function xAt(index, total, left, plotW) {
        if (total <= 1) return left + plotW / 2;
        return left + (plotW * index) / (total - 1);
    }

    function yAt(value, maxVal, top, plotH) {
        var ratio = value / maxVal;
        return top + plotH - (ratio * plotH);
    }

    function formatNum(n) {
        if (typeof n !== 'number') n = Number(n) || 0;
        return n.toLocaleString('ko-KR');
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // Public API
    return {
        lineChart: lineChart,
        barChart: barChart,
        donutChart: donutChart,
        donutLegend: donutLegend,
        colors: colors,
        palette: palette,
        formatNum: formatNum,
    };
})();
