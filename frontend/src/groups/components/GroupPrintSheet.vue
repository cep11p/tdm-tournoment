<script setup>
import { computed } from 'vue'

import { isOfficialGroupSheetKind, printOrientation } from '../utils/groupPrint'
import GroupPrintGenericLayout from './GroupPrintGenericLayout.vue'
import GroupPrintOfficialLayout from './GroupPrintOfficialLayout.vue'

const props = defineProps({
  sheet: {
    type: Object,
    required: true,
  },
  orientation: {
    type: String,
    default: null,
  },
})

const isOfficialSheet = computed(() => isOfficialGroupSheetKind(props.sheet?.sheet_kind))
const resolvedOrientation = computed(() => {
  if (props.orientation === 'landscape' || props.orientation === 'portrait') {
    return props.orientation
  }

  return printOrientation(props.sheet?.best_of)
})
</script>

<template>
  <article
    class="group-print-sheet print-sheet"
    :class="[
      resolvedOrientation === 'landscape' ? 'print--landscape' : 'print--portrait',
      isOfficialSheet ? 'print-sheet--official' : 'print-sheet--generic',
      sheet.sheet_kind ? `print-sheet--${sheet.sheet_kind}` : null,
    ]"
  >
    <GroupPrintOfficialLayout
      v-if="isOfficialSheet"
      :sheet="sheet"
    />
    <GroupPrintGenericLayout
      v-else
      :sheet="sheet"
    />
  </article>
</template>

<style scoped>
.print-sheet {
  background: #fff;
  color: #111;
  padding: 12mm 10mm;
  width: 210mm;
  box-sizing: border-box;
  margin: 0 auto;
}

.print-sheet.print--landscape {
  width: 297mm;
}

.print-sheet--g5 {
  padding: 8mm 8mm;
}

.print-sheet--g4 {
  padding: 10mm 9mm;
}

.print-sheet--official {
  display: flex;
  flex-direction: column;
  break-inside: avoid;
  page-break-inside: avoid;
}

@media screen {
  .print-sheet--official.print--portrait {
    min-height: 297mm;
  }

  .print-sheet--official.print--landscape {
    min-height: 210mm;
  }
}

@media print {
  .print-sheet {
    width: auto;
    max-width: none;
    margin: 0;
    padding: 0;
    box-shadow: none;
  }

  .print-sheet--g5,
  .print-sheet--g4 {
    padding: 0;
  }
}
</style>
