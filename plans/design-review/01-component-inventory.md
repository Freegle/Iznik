# Freegle UI Component Inventory

## Overview

This document catalogs all UI components, patterns, and styles used across the Freegle site, identifying inconsistencies and consolidation opportunities.

---

## 1. Button Components

### Variants Defined (buttons.scss)
| Variant | Background | Text | Border | Use Case |
|---------|------------|------|--------|----------|
| `btn-primary` | Green (#338808) | White | Green | Primary CTA |
| `btn-secondary` | Gray | Dark | Black on hover | Secondary actions |
| `btn-white` | White | Black | Black | Tertiary CTA |
| `btn-warning` | Orange (#e38d13) | White | Green | Warning actions |
| `btn-danger` | Red | White | - | Destructive actions |
| `btn-link` | Transparent | Blue | None | Text links |
| `btn-light` | Inherit | Gray (#6C757D) | None | Minor actions |

### Sizes
- `btn-xs` - Extra small (padding: .25rem .4rem)
- `btn-sm` - Small (Bootstrap default)
- Default - Standard
- `btn-lg` - Large (Bootstrap default)

### Inconsistencies Found

1. **Modal Footer Buttons**
   - `ConfirmModal.vue:10` uses `variant="white"` for Cancel
   - Other modals use `variant="secondary"` for Cancel
   - **Recommendation**: Standardize on `variant="white"` for cancel/dismiss

2. **Disabled State Styling**
   - Primary disabled removes color styling
   - Secondary disabled removes border entirely
   - **Recommendation**: Create consistent disabled state across all variants

3. **Text Shadow**
   - `btn-default` and `btn-warning` have text shadows
   - Other buttons don't
   - **Recommendation**: Remove text shadows for cleaner look

---

## 2. Form Elements

### Input Components Used
- `b-form-input` - Bootstrap-Vue text inputs
- `b-form-textarea` - Standard textareas
- `AutoHeightTextarea.vue` - Custom auto-expanding textarea
- `b-form-select` - Dropdown selects
- `b-form-checkbox` - Checkboxes
- `b-form-radio` - Radio buttons
- `OurDatePicker.vue` - Custom date picker
- `AutoComplete.vue` - Autocomplete input with dropdown
- `GroupSelect.vue` - Group selection component

### File Upload
- `OurUploader.vue` - Uppy.js-based uploader with drag-drop support

### Inconsistencies Found

1. **Validation Display**
   - Some forms show inline errors
   - Some use toast notifications
   - Some highlight field borders
   - **Recommendation**: Standardize on inline errors with `aria-describedby`

2. **Placeholder Text**
   - Inconsistent use of placeholders vs labels
   - Some inputs rely solely on placeholders (accessibility issue)
   - **Recommendation**: Always use visible labels

3. **Required Field Indication**
   - No consistent pattern for marking required fields
   - **Recommendation**: Add asterisk + `aria-required="true"`

---

## 3. Navigation Elements

### Components
- `NavbarDesktop.vue` - Main desktop navigation
- `NavbarMobile.vue` - Mobile hamburger menu
- `SidebarLeft.vue` - Left sidebar navigation
- `SidebarRight.vue` - Right sidebar content
- `MainFooter.vue` - Site footer
- `WizardProgress.vue` - Step indicator for wizards

### Navigation Patterns
- Icon + text combination for nav items
- Badge counts on nav items (chats, notifications)
- Active state highlighting

### Inconsistencies Found

1. **Icon Sizing**
   - Some use `fa-2x` class
   - Some use `size` prop
   - **Recommendation**: Use consistent sizing via props

2. **Active States**
   - Different visual treatments across nav components
   - **Recommendation**: Create unified active state style

3. **Mobile Menu Behavior**
   - Inconsistent close-on-navigate behavior
   - **Recommendation**: Standardize menu closing logic

---

## 4. Cards and Containers

### Card Variants
- Standard `b-card` - White background
- Success border card - Green left border
- Warning border card - Orange left border
- `sidebar-wrapper` - Custom sidebar container

### Inconsistencies Found

1. **Border Styles**
   - Some cards have borders, some don't
   - Mixed use of `border-start` vs full borders
   - **Recommendation**: Define clear card hierarchy

2. **Padding/Spacing**
   - Inconsistent internal padding
   - **Recommendation**: Use standardized spacing scale

3. **Shadow Usage**
   - Some cards have shadows, some don't
   - **Recommendation**: Define elevation system

---

## 5. Typography

### Heading Usage
- H1 - Page titles
- H2 - Section headers
- H3 - Card titles
- H4-H6 - Subsections

### Text Classes
- `.text-muted` - Gray secondary text
- `.text-faded` - Even lighter text
- `.black` / `.white` - Color overrides
- `.truncate` - Single-line truncation
- `.line-clamp-*` - Multi-line truncation

### Inconsistencies Found

1. **Heading Hierarchy**
   - Some pages skip heading levels (H1 to H3)
   - **Recommendation**: Maintain proper heading hierarchy for accessibility

2. **Text Emphasis**
   - Mix of `<b>`, `<strong>`, `font-weight-bold` class
   - **Recommendation**: Standardize on semantic elements

3. **Font Sizes**
   - Mix of Bootstrap utilities and custom sizes
   - **Recommendation**: Define typography scale

---

## 6. Modals and Dialogs

### Modal Types
- Standard modal - Default Bootstrap modal
- Fullscreen modal - `fullscreen` prop
- Ok-only modal - Single action button
- Scrollable modal - `scrollable` prop

### Modal Count
- **102 modal instances** across the codebase

### Inconsistencies Found

1. **Footer Button Order**
   - Cancel | Primary (most common)
   - Primary | Cancel (some instances)
   - **Recommendation**: Standardize Cancel on left, Primary on right

2. **Footer Button Variants**
   - White vs Secondary for cancel
   - **Recommendation**: Use `white` for cancel consistently

3. **Modal Sizing**
   - Inconsistent use of `size` prop
   - Some modals too small for content
   - **Recommendation**: Define modal size guidelines

4. **Focus Management**
   - `no-trap` used in LoginModal (accessibility concern)
   - **Recommendation**: Enable focus trapping by default

---

## 7. Loading States

### Implementations Found (4 different approaches!)
1. **GIF-based loader** - `loader.gif` image
2. **LoadingIndicator component** - Vue component
3. **JumpingDots animation** - CSS animation with dots
4. **Bootstrap spinner** - `b-spinner` component

### Inconsistencies Found

1. **Multiple Loading Patterns**
   - Different visual treatments for same purpose
   - **Recommendation**: Consolidate to single `LoadingIndicator` component

2. **Progress Indicators**
   - Custom SVG progress in some places
   - Bootstrap progress elsewhere
   - **Recommendation**: Standardize progress component

---

## 8. Icons

### Icon System
- Font Awesome via `v-icon` component
- Usage: `<v-icon icon="heart" />`

### Sizing Methods
1. Font Awesome classes: `fa-2x`, `fa-lg`
2. Size prop: `size="2x"`

### Inconsistencies Found

1. **Mixed Sizing Approaches**
   - **Recommendation**: Use `size` prop consistently

2. **Icon Layout**
   - Some icons inline, some flex-centered
   - **Recommendation**: Create icon wrapper component

3. **Missing Accessible Labels**
   - Many icon-only buttons lack `aria-label`
   - **Recommendation**: Require labels on icon buttons

---

## 9. Color System

### Color Variables (_color-vars.scss)

#### Primary Palette
| Variable | Value | Usage |
|----------|-------|-------|
| `$colour-success` | #338808 | Primary green |
| `$colour-secondary` | #00A1CB | Secondary blue |
| `$colour-warning` | #e38d13 | Warning orange |
| `$colour-header` | #1d6607 | Header green |

#### Grays
| Variable | Value |
|----------|-------|
| `$color-gray--normal` | #6C757D |
| `$color-gray--faded` | #6C757D70 |
| `$color-gray--lighter` | #F5F5F5 |
| `$color-gray--light` | #CDCDCD |
| `$color-gray--base` | #A9A9A9 |
| `$color-gray--dark` | #808080 |
| `$color-gray--darker` | #212529 |

### Inconsistencies Found

1. **Naming Convention**
   - Mix of `colour` (British) and `color` (American)
   - **Recommendation**: Standardize on one spelling

2. **Color Proliferation**
   - 50+ color variables defined
   - Many similar shades (7 grays, 6 greens)
   - **Recommendation**: Consolidate to design system palette

3. **Contrast Issues**
   - `$color-gray--base` (#A9A9A9) fails WCAG on white
   - `$color-gray--normal` (#6C757D) borderline contrast
   - **Recommendation**: Audit all text colors for contrast

---

## 10. Spacing and Layout

### Layout Patterns
- Bootstrap grid system (row/col)
- Flexbox utilities (d-flex, justify-content, align-items)
- Custom sidebar calculations

### Spacing
- Bootstrap spacing utilities (m-*, p-*)
- Custom margins/padding in component styles

### Inconsistencies Found

1. **Spacing Scale**
   - Mix of Bootstrap scale and custom values
   - **Recommendation**: Use only Bootstrap spacing scale

2. **Layout Calculations**
   - Complex calc() in sidebar components
   - **Recommendation**: Simplify with CSS Grid

3. **Responsive Breakpoints**
   - Inconsistent breakpoint usage
   - **Recommendation**: Document responsive strategy

---

## Summary: Component Consolidation Opportunities

| Category | Current State | Consolidation |
|----------|---------------|---------------|
| Buttons | 6 variants, inconsistent | Reduce to 4 core variants |
| Loading | 4 implementations | Single component |
| Colors | 50+ variables | 24-color palette |
| Icons | 2 sizing methods | Prop-only sizing |
| Modals | Multiple patterns | Standardized templates |
| Forms | Varied validation | Unified validation pattern |

### Priority Actions

1. **High**: Consolidate loading states to single component
2. **High**: Standardize modal footer patterns
3. **Medium**: Unify color naming convention
4. **Medium**: Create button disabled state consistency
5. **Low**: Simplify color palette
6. **Low**: Document spacing scale
