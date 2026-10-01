<script setup>
import { EllipsisVerticalIcon } from '@heroicons/vue/24/outline'
import { computed, onBeforeUnmount, ref, watch } from 'vue'

const props = defineProps({
  open: {
    type: Boolean,
    default: false,
  },
  canEdit: {
    type: Boolean,
    default: false,
  },
  hasMoveTargets: {
    type: Boolean,
    default: false,
  },
  lockMessage: {
    type: String,
    default: '',
  },
  moveLabel: {
    type: String,
    default: 'Mover a otro grupo',
  },
  removeLabel: {
    type: String,
    default: 'Quitar del grupo',
  },
  menuLabel: {
    type: String,
    default: 'Acciones del integrante',
  },
})

const emit = defineEmits(['toggle', 'close', 'move', 'remove'])

const rootEl = ref(null)
const menuStyle = ref({})

const canMove = computed(() => props.canEdit && props.hasMoveTargets)

const helperMessage = computed(() => {
  if (!props.canEdit) {
    return props.lockMessage
  }

  if (!props.hasMoveTargets) {
    return 'No hay otro grupo en esta competencia.'
  }

  return ''
})

const handleDocumentClick = (event) => {
  if (!props.open) {
    return
  }

  if (rootEl.value?.contains(event.target)) {
    return
  }

  emit('close')
}

const placeMenu = () => {
  const button = rootEl.value?.querySelector('button')

  if (!button) {
    return
  }

  const rect = button.getBoundingClientRect()
  const menuWidth = 256
  const menuHeight = 148
  const padding = 8
  const left = Math.max(
    padding,
    Math.min(rect.right - menuWidth, window.innerWidth - menuWidth - padding),
  )
  const openUp = window.innerHeight - rect.bottom < menuHeight && rect.top > menuHeight
  const top = openUp ? rect.top - menuHeight - 4 : rect.bottom + 4

  menuStyle.value = {
    position: 'fixed',
    top: `${top}px`,
    left: `${left}px`,
    width: `${menuWidth}px`,
    zIndex: '30',
  }
}

const startMenuListeners = () => {
  document.addEventListener('click', handleDocumentClick)
  window.addEventListener('scroll', placeMenu, true)
  window.addEventListener('resize', placeMenu)
  placeMenu()
}

const stopMenuListeners = () => {
  document.removeEventListener('click', handleDocumentClick)
  window.removeEventListener('scroll', placeMenu, true)
  window.removeEventListener('resize', placeMenu)
}

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) {
      startMenuListeners()
      return
    }

    stopMenuListeners()
  },
)

onBeforeUnmount(stopMenuListeners)

const handleMove = () => {
  if (!canMove.value) {
    return
  }

  emit('move')
}

const handleRemove = () => {
  if (!props.canEdit) {
    return
  }

  emit('remove')
}
</script>

<template>
  <div ref="rootEl" class="relative">
    <button
      type="button"
      class="inline-flex h-8 w-8 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100"
      :aria-label="menuLabel"
      :aria-expanded="open ? 'true' : 'false'"
      aria-haspopup="menu"
      @click.stop="emit('toggle')"
    >
      <EllipsisVerticalIcon class="h-5 w-5" aria-hidden="true" />
    </button>

    <div
      v-if="open"
      class="rounded-md border border-slate-200 bg-white py-1 shadow-lg dark:border-slate-700 dark:bg-slate-900"
      :style="menuStyle"
      role="menu"
    >
      <button
        type="button"
        class="block w-full px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:text-slate-400 disabled:hover:bg-transparent dark:text-slate-200 dark:hover:bg-slate-800 dark:disabled:text-slate-500"
        :disabled="!canMove"
        :title="!canMove ? helperMessage : ''"
        role="menuitem"
        @click.stop="handleMove"
      >
        {{ moveLabel }}
      </button>
      <button
        type="button"
        class="block w-full px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:text-slate-400 disabled:hover:bg-transparent dark:text-slate-200 dark:hover:bg-slate-800 dark:disabled:text-slate-500"
        :disabled="!canEdit"
        :title="!canEdit ? lockMessage : ''"
        role="menuitem"
        @click.stop="handleRemove"
      >
        {{ removeLabel }}
      </button>
      <p
        v-if="helperMessage"
        class="border-t border-slate-100 px-3 py-2 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400"
      >
        {{ helperMessage }}
      </p>
    </div>
  </div>
</template>
