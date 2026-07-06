<script setup>
import { computed, onBeforeUnmount, watch } from 'vue'

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  title: {
    type: String,
    required: true,
  },
  description: {
    type: String,
    default: '',
  },
  size: {
    type: String,
    default: 'md',
    validator: (value) => ['sm', 'md', 'lg', 'xl'].includes(value),
  },
  preventClose: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['close'])

const titleId = 'base-modal-title'

const sizeClasses = computed(() => {
  switch (props.size) {
    case 'sm':
      return 'max-w-sm'
    case 'lg':
      return 'max-w-xl'
    case 'xl':
      return 'max-w-2xl'
    case 'md':
    default:
      return 'max-w-lg'
  }
})

const handleClose = () => {
  if (props.preventClose) {
    return
  }

  emit('close')
}

const handleKeydown = (event) => {
  if (event.key !== 'Escape' || !props.show || props.preventClose) {
    return
  }

  event.preventDefault()
  emit('close')
}

watch(
  () => props.show,
  (isVisible) => {
    if (isVisible) {
      document.addEventListener('keydown', handleKeydown)
      return
    }

    document.removeEventListener('keydown', handleKeydown)
  },
  { immediate: true },
)

onBeforeUnmount(() => {
  document.removeEventListener('keydown', handleKeydown)
})
</script>

<template>
  <Teleport to="body">
    <div
      v-if="show"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4"
      @click.self="handleClose"
    >
      <div
        class="flex max-h-[90vh] w-full flex-col overflow-hidden rounded-md border border-slate-200 bg-white text-sm shadow-xl dark:border-slate-700 dark:bg-slate-900"
        :class="sizeClasses"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="titleId"
      >
        <div class="space-y-4 overflow-y-auto p-4">
          <div>
            <h2 :id="titleId" class="text-lg font-semibold text-slate-900 dark:text-slate-100">
              {{ title }}
            </h2>
            <p v-if="description" class="mt-1 text-slate-600 dark:text-slate-300">
              {{ description }}
            </p>
          </div>

          <slot />
        </div>

        <div
          v-if="$slots.footer"
          class="flex justify-end gap-2 border-t border-slate-200 p-4 dark:border-slate-700"
        >
          <slot name="footer" />
        </div>
      </div>
    </div>
  </Teleport>
</template>
