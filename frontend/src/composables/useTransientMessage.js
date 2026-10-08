import { onScopeDispose, reactive } from 'vue'

export function useTransientMessage(ms = 3500) {
  const state = reactive({ message: '' })
  let timer = null

  const clear = () => {
    clearTimeout(timer)
    timer = null
    state.message = ''
  }

  const show = (text) => {
    clearTimeout(timer)
    state.message = text
    timer = setTimeout(() => {
      state.message = ''
      timer = null
    }, ms)
  }

  onScopeDispose(clear)

  return Object.assign(state, { show, clear })
}
