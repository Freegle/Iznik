# Vitest Vue Component Testing Skill

## Test File Structure

```javascript
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import ComponentName from '~/path/to/Component.vue'

const mockStore = { method: vi.fn() }
vi.mock('~/stores/storename', () => ({ useStoreStore: () => mockStore }))

// For composables with refs used in templates, return object directly
vi.mock('~/composables/useMe', () => ({
  useMe: () => ({
    me: { id: 999 }, // Direct object for template refs
    myGroups: { value: [] }, // Can use value for script-only refs
  }),
}))

describe('ComponentName', () => {
  const createData = (overrides = {}) => ({ id: 123, ...overrides })

  function mountComponent(props = {}) {
    return mount(ComponentName, {
      props: { data: createData(), ...props },
      global: {
        stubs: { 'b-button': { template: '<button @click="$emit(\'click\')"><slot /></button>' } },
        mocks: { timeago: (val) => 'ago' },
      },
    })
  }

  beforeEach(() => vi.clearAllMocks())
})
```

## Key Mocking Patterns

| Pattern | Correct | Wrong |
|---------|---------|-------|
| Auto-imported components | Use `global.stubs` | `vi.mock()` won't work |
| Template refs | `me: { id: 999 }` | `me: { value: { id: 999 } }` |
| Nuxt app features | `globalThis.useNuxtApp = () => ({ $api })` | - |

## Don't Test the Framework

**TEST YOUR CODE:**
- Business logic conditions
- User interactions (clicks, form submissions)
- Store/API calls
- Error vs success states

**DON'T TEST:**
- Vue's reactivity, v-if, v-bind, interpolation
- That components mount (`expect(wrapper.exists()).toBe(true)`)
- Prop types/defaults
- Initial state values

## Consolidation Patterns

### 1. Use `it.each()` for Mappings
```javascript
it.each([
  ['api', 'API'], ['client', 'User'], ['email', 'Email'],
])('%s → %s', (source, expected) => {
  expect(mountComponent({ log: { source } }).vm.sourceLabel).toBe(expected)
})
```

### 2. Combine "Returns X or Null"
```javascript
it('returns value when present, null when missing', () => {
  expect(mountComponent({ raw: { subject: 'Test' } }).vm.subject).toBe('Test')
  expect(mountComponent({ raw: {} }).vm.subject).toBeNull()
})
```

### 3. Combine Show/Hide Toggles
```javascript
it('shows by default, hides when flag true', () => {
  expect(mountComponent().find('.col').exists()).toBe(true)
  expect(mountComponent({ hideCol: true }).find('.col').exists()).toBe(false)
})
```

### 4. Remove Redundant "Angle" Tests
If rendering tests check items are limited, don't duplicate in "computed itemsToShow" tests.

### 5. Test Related Computed Properties Together
```javascript
it('returns event ID and derived URL', () => {
  const w = mountComponent({ raw: { sentry_event_id: 'abc' } })
  expect(w.vm.sentryEventId).toBe('abc')
  expect(w.vm.sentryUrl).toContain('abc')
})
```

## Tests to NEVER Write

```javascript
// DELETE ALL OF THESE:
it('mounts successfully', () => { expect(wrapper.exists()).toBe(true) })
it('renders the component', () => { ... })
it('accepts X prop', () => { ... })
it('X is Number type', () => { ... })
it('X defaults to null', () => { ... })
it('starts with loading as false', () => { ... })
```

## Consolidation Checklist

**Within Files:**
- [ ] Multiple tests for value mapping? → `it.each()`
- [ ] "Returns X" + "Returns null"? → Combine
- [ ] Same pattern for 3+ entities? → Test all together
- [ ] Testing A and B where B derives from A? → Test together
- [ ] Show/hide for same condition? → Test both states

**Across Files:**
- [ ] Similar components (Export*, Mod*Button)? → Share stubs/helpers
- [ ] Duplicate mocks across tests? → Create `tests/unit/mocks/` helpers
- [ ] Props tests that duplicate rendering tests? → Delete props section

## Running Tests

```bash
npx vitest run                                    # All tests
npx vitest run tests/unit/components/X.spec.js   # Specific file
npx vitest run -t "test name"                    # By name
```

## Memory-Safe Config (WSL)

```typescript
export default defineConfig({
  test: {
    pool: 'forks',
    maxWorkers: process.env.CI ? 2 : Math.max(1, Math.floor(os.cpus().length / 2)),
  },
})
```
