class StarRating {
    constructor(selector, options = {}) {
        this.container = typeof selector === 'string'
            ? document.querySelector(selector)
            : selector;

        if (!this.container) {
            console.log("Invalid selector");
            return false;
        }

        this.options = {
            mode: 'display',        // display | input
            rating: 0,              // 실제 점수
            maxScore: 10,           // ⭐ 만점 점수
            maxStars: 5,            // 출력 별 개수
            step: 0.5,              // ⭐ 입력 단위 (고정)

            iconType: 'star',
            customIcon: '',

            starSize: '1.5rem',
            starColor: 'gold',
            starEmptyColor: '#ccc',
            starGap: '0',

            inputName: null,
            showScore: false,
            liveHoverScore: false,

            scoreTextSize: '1rem',
            scoreTextColor: '#333',
            scoreTextMargin: '0.25rem',

            onRate: null,
            ...options
        };

        this.currentScore = this.snapStep(this.options.rating);
        this.hoverScore = null;

        this.injectStyles();
        this.buildLayout();
    }
    
    /* =========================
     * 기본 유틸
     * ========================= */

    snapStep(value) {
        const step = this.options.step;
        return Math.round(value / step) * step;
    }

    scoreToStar(score) {
        // 점수 체계와 별 체계가 동일한 경우
        if (this.options.maxScore === this.options.maxStars) {
            return score;
        }
        return (score / this.options.maxScore) * this.options.maxStars;
    }

    starToScore(starValue) {
        return (starValue / this.options.maxStars) * this.options.maxScore;
    }

    injectStyles() {
        if (document.getElementById('star-rating-style')) return;

        const style = document.createElement('style');
        style.id = 'star-rating-style';
        style.textContent = `
            .star-rating {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                flex-wrap: wrap;
                cursor: pointer;
            }
            .star-rating.readonly {
                cursor: default;
            }
            .star-rating svg {
                display: block;
                flex-shrink: 0;
            }
        `;
        document.head.appendChild(style);
    }

    /* =========================
     * 레이아웃
     * ========================= */

    buildLayout() {
        this.container.innerHTML = '';
        this.container.classList.add('star-rating');

        if (this.options.mode === 'display') {
            this.container.classList.add('readonly');
        }

        this.container.style.gap = this.options.starGap;

        if (this.options.inputName && this.options.mode === 'input') {
            this.hiddenInput = document.createElement('input');
            this.hiddenInput.type = 'hidden';
            this.hiddenInput.name = this.options.inputName;
            this.hiddenInput.value = this.currentScore;
            this.container.insertAdjacentElement('afterend', this.hiddenInput);
        }

        if (this.options.showScore) {
            this.scoreText = document.createElement('span');
            this.scoreText.style.fontSize = this.options.scoreTextSize;
            this.scoreText.style.color = this.options.scoreTextColor;
            this.scoreText.style.marginLeft = this.options.scoreTextMargin;
            this.container.appendChild(this.scoreText);
        }

        this.starElems = [];

        for (let i = 1; i <= this.options.maxStars; i++) {
            const star = this.createIcon(i);

            if (this.options.mode === 'input') {
                if (this.options.liveHoverScore) {
                    star.addEventListener('mousemove', e => this.onHoverMove(e, i));
                } else {
                    star.addEventListener('mousemove', e => this.onHover(e, i));
                }
                star.addEventListener('mouseleave', () => this.onHover(null));
                star.addEventListener('click', e => this.onClick(e, i));
            }

            this.container.insertBefore(star, this.scoreText || null);
            this.starElems.push(star);
        }

        this.updateDisplay();
    }

    createIcon(index) {
        const icons = {
            // star: "M12 17.27L18.18 21 16.54 13.97 22 9.24 14.81 8.63 12 2 9.19 8.63 2 9.24 7.45 13.97 5.82 21z",
            // heart: "M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 6 4 4 6.5 4 8.28 4 10 5.4 12 7.5 14 5.4 15.72 4 17.5 4 20 4 22 6 22 8.5 22 12.28 18.6 15.36 13.45 20.04z",
            star: "M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z",
            heart: "M2 9.5a5.5 5.5 0 0 1 9.591-3.676.56.56 0 0 0 .818 0A5.49 5.49 0 0 1 22 9.5c0 2.29-1.5 4-3 5.5l-5.492 5.313a2 2 0 0 1-3 .019L5 15c-1.5-1.5-3-3.2-3-5.5",
            circle: "M12 2a10 10 0 1 0 0 20 10 10 0 1 0 0-20z",
            square: "M4 4h16v16H4z",
            diamond: "M12 2l10 10-10 10-10-10z",
            thumb: "M1 21h4V9H1v12zm22-11c0-1.1-.9-2-2-2h-6.31l.95-4.57.03-.32c0-.41-.17-.79-.44-1.06L14.17 1 7.59 7.59C7.22 7.95 7 8.45 7 9v10c0 1.1.9 2 2 2h9c.83 0 1.54-.5 1.84-1.22l3.02-7.05.1-.5v-1.28z",
            smile: "M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 15c-2.33 0-4.31-1.46-5.11-3.5h10.22C16.31 15.54 14.33 17 12 17zm-4-7c-.83 0-1.5-.67-1.5-1.5S7.17 7 8 7s1.5.67 1.5 1.5S8.83 10 8 10zm8 0c-.83 0-1.5-.67-1.5-1.5S15.17 7 16 7s1.5.67 1.5 1.5S16.83 10 16 10z"
        };

        const pathD = this.options.iconType === 'custom'
            ? this.options.customIcon
            : icons[this.options.iconType] || icons.star;

        const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
        svg.style.width = this.options.starSize;
        svg.style.height = this.options.starSize;
        svg.setAttribute("viewBox", "0 0 24 24");
        svg.dataset.index = index;

        const bg = document.createElementNS("http://www.w3.org/2000/svg", "path");
        bg.setAttribute("d", pathD);
        bg.setAttribute("fill", this.options.starEmptyColor);
        svg.appendChild(bg);

        const fg = document.createElementNS("http://www.w3.org/2000/svg", "path");
        fg.setAttribute("d", pathD);
        fg.setAttribute("fill", this.options.starColor);
        fg.classList.add("star-fill");
        svg.appendChild(fg);

        return svg;
    }


    /* =========================
     * 출력 갱신
     * ========================= */

    updateDisplay() {
        const score = Math.min(
            Math.max(this.hoverScore ?? this.currentScore, 0),
            this.options.maxScore
        );

        const starRating = this.scoreToStar(score);

        this.starElems.forEach((svg, i) => {
            const index = i + 1;
            const fill = Math.min(Math.max(starRating - index + 1, 0), 1);
            const fillElem = svg.querySelector('.star-fill');
            fillElem.setAttribute(
                'clip-path',
                `inset(0 ${(1 - fill) * 100}% 0 0)`
            );
        });

        if (this.hiddenInput) {
            this.hiddenInput.value = score;
        }

        if (this.options.showScore && this.scoreText) {
            this.scoreText.textContent = `${score.toFixed(1)}점`;
        }
    }

    /* =========================
     * 이벤트
     * ========================= */

    onHover(e, index) {
        if (e === null) {  // mouseleave 처리
            this.hoverScore = null;
            this.updateDisplay();
            return;
        }

        const rect = e.currentTarget.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const half = rect.width / 2;
        
        const baseScore = this.starToScore(index - 1);
        const scorePerStar = this.options.maxScore / this.options.maxStars;
        const score = baseScore + (x < half ? scorePerStar / 2 : scorePerStar);
        
        // step 방어
        this.hoverScore = this.snapStep(score);
        this.updateDisplay();
    }

    onHoverMove(e, index) {
        const rect = e.currentTarget.getBoundingClientRect();
        const x = e.clientX - rect.left;

        const totalSteps = this.options.maxScore / this.options.step;
        const stepsPerStar = totalSteps / this.options.maxStars;
        const stepWidth = rect.width / stepsPerStar;

        let stepIndex = Math.floor(x / stepWidth);
        stepIndex = Math.max(0, Math.min(stepIndex, stepsPerStar - 1));

        const baseSteps = (index - 1) * stepsPerStar;
        const score = (baseSteps + stepIndex + 1) * this.options.step;

        // step 방어 (혹시 모를 부동소수점 오차 방지)
        this.hoverScore = this.snapStep(score);
        this.updateDisplay();
    }

    onClick(e, index) {
        const rect = e.currentTarget.getBoundingClientRect();
        const x = e.clientX - rect.left;

        const totalSteps = this.options.maxScore / this.options.step;
        const stepsPerStar = totalSteps / this.options.maxStars;
        const stepWidth = rect.width / stepsPerStar;

        let stepIndex = Math.floor(x / stepWidth);
        stepIndex = Math.max(0, Math.min(stepIndex, stepsPerStar - 1));

        const baseSteps = (index - 1) * stepsPerStar;
        const score = (baseSteps + stepIndex + 1) * this.options.step;

        // step 방어 (혹시 모를 부동소수점 오차 방지)
        this.currentScore = this.snapStep(score);
        this.hoverScore = null;

        this.updateDisplay();
        this.options.onRate?.(this.currentScore);
    }

    getRating() {
        return this.currentScore;
    }

    setRating(val) {
        // step 방어
        this.currentScore = this.snapStep(
            Math.min(Math.max(val, 0), this.options.maxScore)
        );
        this.updateDisplay();
    }
}