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

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) {
      document.addEventListener('click', handleDocumentClick)
      return
    }

    document.removeEventListener('click', handleDocumentClick)
  },
)

onBeforeUnmount(() => {
  document.removeEventListener('click', handleDocumentClick)
})

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
      class="absolute right-0 z-20 mt-1 w-64 rounded-md border border-slate-200 bg-white py-1 shadow-lg dark:border-slate-700 dark:bg-slate-900"
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
