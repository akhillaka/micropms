# Design Plan: MicroPMS UI Advancement & System Alignment

## Summary
- **Surface**: Guest Booking Engine (`public_html/index.php`) & Global Theme Styles (`public_html/css/style.css`)
- **Objective**: Align global UI styling with documented Liquid Glass Design System (`MASTER.md`).

## Target Modifications

### `public_html/css/style.css`
1. **Typography Harmonization**:
   - Replace `@import url('https://fonts.googleapis.com/css2?family=Inter...` with `@import url('https://fonts.googleapis.com/css2?family=Karla:wght@300;400;500;600;700&family=Playfair+Display+SC:wght@400;700&display=swap');`
   - Set `body` to `font-family: 'Karla', sans-serif;`
   - Set `h1, h2, h3, h4, h5, h6, .font-display` to `font-family: 'Playfair Display SC', serif;`

2. **Liquid Glass Styling**:
   - Update `.card, .card-glass` background to `background: rgba(255, 255, 255, 0.85);` with `backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);`

3. **Accessibility**:
   - Add `:focus-visible` styling for interactive buttons and inputs (`outline: 2px solid var(--color-primary); outline-offset: 2px;`)
