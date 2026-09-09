<script setup>
import { computed } from 'vue'

import { formatPrintDisplayName } from '../utils/groupPrint'

const props = defineProps({
  match: {
    type: Object,
    required: true,
  },
  setColumns: {
    type: Array,
    required: true,
  },
})

const refereeName = computed(() => {
  const name = props.match?.referee?.display_name

  if (!name) {
    return ''
  }

  return formatPrintDisplayName(name)
})
</script>

<template>
  <table class="match-block">
    <colgroup>
      <col class="match-col-num">
      <col class="match-col-name">
      <col class="match-col-result">
      <col
        v-for="setNumber in setColumns"
        :key="`col-s-${setNumber}`"
        class="match-col-set"
      >
      <col class="match-col-ref">
    </colgroup>
    <thead>
      <tr>
        <th>Nº</th>
        <th>Participante</th>
        <th>Resultado</th>
        <th
          v-for="setNumber in setColumns"
          :key="`h-s-${setNumber}`"
        >
          S{{ setNumber }}
        </th>
        <th>Juez</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td class="match-num">{{ match.side1_number ?? '' }}</td>
        <td class="match-name">{{ formatPrintDisplayName(match.side1?.display_name) }}</td>
        <td class="match-empty" />
        <td
          v-for="setNumber in setColumns"
          :key="`s1-${setNumber}`"
          class="match-empty"
        />
        <td
          class="match-ref"
          rowspan="2"
        >
          <span
            v-if="refereeName"
            class="match-ref-name"
          >{{ refereeName }}</span>
        </td>
      </tr>
      <tr>
        <td class="match-num">{{ match.side2_number ?? '' }}</td>
        <td class="match-name">{{ formatPrintDisplayName(match.side2?.display_name) }}</td>
        <td class="match-empty" />
        <td
          v-for="setNumber in setColumns"
          :key="`s2-${setNumber}`"
          class="match-empty"
        />
      </tr>
    </tbody>
  </table>
</template>

<style scoped>
.match-block {
  width: 100%;
  border-collapse: collapse;
  table-layout: fixed;
  page-break-inside: avoid;
  break-inside: avoid;
}

.match-col-num {
  width: 7mm;
}

.match-col-name {
  width: auto;
}

.match-col-result {
  width: 14mm;
}

.match-col-set {
  width: 8.5mm;
}

.match-col-ref {
  width: 28mm;
}

.match-block th,
.match-block td {
  border: 1px solid #222;
  padding: 0.12rem 0.18rem;
  vertical-align: middle;
  background: #fff;
  color: #111;
}

.match-block thead th {
  background: #f3f3f3;
  font-weight: 700;
  text-align: center;
  font-size: 0.62rem;
  letter-spacing: 0.02em;
  text-transform: uppercase;
}

.match-num {
  text-align: center;
  font-weight: 700;
}

.match-name {
  white-space: pre-line;
  overflow-wrap: anywhere;
  word-break: break-word;
  line-height: 1.15;
}

.match-empty {
  height: 1.15rem;
}

.match-ref {
  text-align: center;
  vertical-align: middle;
  font-size: 0.68em;
  line-height: 1.15;
}

.match-ref-name {
  white-space: pre-line;
  overflow-wrap: anywhere;
  word-break: break-word;
}

@media print {
  .match-block th,
  .match-block td {
    background: #fff !important;
    color: #111 !important;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }

  .match-block thead th {
    background: #f3f3f3 !important;
  }
}
</style>
