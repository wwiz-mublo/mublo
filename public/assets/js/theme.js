(() => {
  'use strict'

  const getStoredTheme = () => localStorage.getItem('theme')
  const setStoredTheme = theme => localStorage.setItem('theme', theme)
  const getPreferredTheme = () => {
    const storedTheme = getStoredTheme()
    if (['light', 'dark'].includes(storedTheme)) {
      return storedTheme
    }
    return 'auto';
  }
  const setTheme = theme => {
    if (theme === 'auto') {
      document.documentElement.setAttribute('data-bs-theme', (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'))
    } else {
      document.documentElement.setAttribute('data-bs-theme', theme)
    }
  }

  setTheme(getPreferredTheme())

  const showActiveTheme = (theme, focus = false) => {
    // Theme Select
    const themeSelect = document.querySelector('.theme-select')
    if (themeSelect) {
      const themeSelectButton = document.querySelector('#theme-select')
      const themeSelectButtonText = document.querySelector('#theme-select-text')
      const themeSelectButtonIcon = document.querySelector('#theme-select-icon')
      const themeSelectActive = themeSelect.querySelector(`[data-bs-theme-value="${theme}"]`)
      const themeSelectActiveText = themeSelectActive.querySelector(`[data-theme-pickup-text]`)
      const themeSelectActiveMark = themeSelectActive.querySelector(`[data-theme-pickup-mark]`)
      const themeSelectActiveIcon = themeSelectActive.querySelector(`[data-theme-pickup-icon]`)
      themeSelect.querySelectorAll('[data-bs-theme-value]').forEach(element => {
        element.classList.remove('active')
        element.setAttribute('aria-pressed', 'false')
        element.querySelector(`[data-theme-pickup-mark]`).classList.add('d-none')
      })
      themeSelectActive.classList.add('active')
      themeSelectActive.setAttribute('aria-pressed', 'true')
      themeSelectActiveMark.classList.remove('d-none')
      themeSelectButtonIcon.classList.remove(themeSelectButtonIcon.getAttribute('class'))
      themeSelectButtonIcon.classList.add(themeSelectActiveIcon.getAttribute('class'))
      themeSelectButtonText.innerText = themeSelectActiveText.innerText
      const themeSelectLabel = `${themeSelectButtonText.textContent} (${themeSelectActive.dataset.bsThemeValue})`
      themeSelectButton.setAttribute('aria-label', themeSelectLabel)
      if (focus) {
        themeSelectButton.focus()
      }
    }

    // Theme Switch
    const themeSwitch = document.querySelector('.theme-switch')
    if (themeSwitch) {
      const themeSwitchActive = themeSwitch.querySelector(`[data-bs-theme-value="${theme}"]`)
      themeSwitch.querySelectorAll('[data-bs-theme-value]').forEach(element => {
        element.classList.remove('active')
        element.setAttribute('aria-pressed', 'false')
      })
      themeSwitchActive.classList.add('active')
      themeSwitchActive.setAttribute('aria-pressed', 'true')
      if (focus) {
        themeSwitch.focus()
      }
    }

    // Theme Toggle
    const themeToggle = document.querySelector('.theme-toggle')
    if (themeToggle) {
      const themeToggleActive = themeToggle.querySelector(`[data-bs-theme-value="${theme}"]`)
      themeToggle.querySelectorAll('[data-bs-theme-value]').forEach(element => {
        element.classList.remove('active')
        element.setAttribute('aria-pressed', 'false')
      })
      themeToggleActive.classList.add('active')
      themeToggleActive.setAttribute('aria-pressed', 'true')
      const themeToggleButtonDark  = themeToggle.querySelector(`[data-bs-theme-value="dark"]`);
      const themeToggleButtonLight = themeToggle.querySelector(`[data-bs-theme-value="light"]`);
      const later = ['light', 'dark'].includes(theme)
        ? (theme === 'light' ? 'dark' : 'light')
        : (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'light' : 'dark')
      switch (later) {
        case 'dark': 
          themeToggleButtonLight.classList.add('d-none');
          themeToggleButtonDark.classList.remove('d-none');
          break;
        case 'light': 
          themeToggleButtonDark.classList.add('d-none');
          themeToggleButtonLight.classList.remove('d-none');
          break;
      }
      if (focus) {
        themeToggle.focus()
      }
    }
  }

  window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
    const storedTheme = getStoredTheme()
    if (storedTheme !== 'light' && storedTheme !== 'dark') {
      setTheme(getPreferredTheme())
    }
  })

  window.addEventListener('DOMContentLoaded', () => {
    showActiveTheme(getPreferredTheme())
    document.querySelectorAll('[data-bs-theme-value]').forEach(toggle => {
      toggle.addEventListener('click', () => {
        const theme = toggle.getAttribute('data-bs-theme-value')
        setStoredTheme(theme)
        setTheme(theme)
        showActiveTheme(theme, true)
      })
    })
  })
})()