<script setup>
import { computed } from 'vue'

import {
  formatPrintDisplayName,
  printCompetitionTypeLabel,
  printOrientation,
  printRoundLabel,
  printSetColumns,
} from '../utils/groupPrint'

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

const bestOf = computed(() => Number(props.sheet?.best_of) || 3)
const setColumns = computed(() => printSetColumns(bestOf.value))
const resolvedOrientation = computed(() => {
  if (props.orientation === 'landscape' || props.orientation === 'portrait') {
    return props.orientation
  }

  return printOrientation(bestOf.value)
})
</script>

<template>
  <article
    class="group-print-sheet print-sheet"
    :class="resolvedOrientation === 'landscape' ? 'print--landscape' : 'print--portrait'"
  >
    <header class="print-header">
      <h1 class="print-title">{{ sheet.group?.name }}</h1>
      <dl class="print-meta">
        <div>
          <dt>Torneo</dt>
          <dd>{{ sheet.tournament?.name || '—' }}</dd>
        </div>
        <div>
          <dt>Competencia</dt>
          <dd>{{ sheet.competition?.name || '—' }}</dd>
        </div>
        <div>
          <dt>Modalidad</dt>
          <dd>{{ printCompetitionTypeLabel(sheet.competition?.type) }}</dd>
        </div>
        <div>
          <dt>Formato</dt>
          <dd>Mejor de {{ sheet.best_of }}</dd>
        </div>
        <div>
          <dt>Clasifican</dt>
          <dd>{{ sheet.qualified_per_group }}</dd>
        </div>
      </dl>
    </header>

    <table class="print-table">
      <thead>
        <tr>
          <th rowspan="2" class="print-col-order">#</th>
          <th rowspan="2" class="print-col-round">Ronda</th>
          <th rowspan="2">Participante A</th>
          <th rowspan="2">Participante B</th>
          <th rowspan="2">Árbitro</th>
          <th
            v-for="setNumber in setColumns"
            :key="`set-h-${setNumber}`"
            colspan="2"
            class="print-col-set"
          >
            Set {{ setNumber }}
          </th>
          <th rowspan="2" class="print-col-result">Res.</th>
          <th rowspan="2" class="print-col-notes">Obs.</th>
        </tr>
        <tr>
          <template v-for="setNumber in setColumns" :key="`set-sub-${setNumber}`">
            <th class="print-col-score">A</th>
            <th class="print-col-score">B</th>
          </template>
        </tr>
      </thead>
      <tbody>
        <tr v-for="match in sheet.matches" :key="match.game_id">
          <td class="print-center">{{ match.order }}</td>
          <td class="print-center">{{ printRoundLabel(match) }}</td>
          <td>
            <span class="print-name">{{ formatPrintDisplayName(match.side1?.display_name) }}</span>
          </td>
          <td>
            <span class="print-name">{{ formatPrintDisplayName(match.side2?.display_name) }}</span>
          </td>
          <td>
            <template v-if="match.referee">
              <span class="print-name">{{ formatPrintDisplayName(match.referee.display_name) }}</span>
            </template>
            <template v-else>
              <span class="print-missing-ref">—</span>
              <span class="print-ref-line" aria-hidden="true" />
            </template>
          </td>
          <template v-for="setNumber in setColumns" :key="`${match.game_id}-set-${setNumber}`">
            <td class="print-score-cell" />
            <td class="print-score-cell" />
          </template>
          <td class="print-result-cell" />
          <td class="print-notes-cell" />
        </tr>
      </tbody>
    </table>

    <footer class="print-footer">
      <p><strong>Observaciones</strong></p>
      <div class="print-notes-block" />
      <p class="print-sign">Mesa / responsable: ________________________________</p>
    </footer>
  </article>
</template>

<style scoped>
.print-sheet {
  background: #fff;
  color: #111;
  padding: 12mm 10mm;
  max-width: 210mm;
  margin: 0 auto;
}

.print-sheet.print--landscape {
  max-width: 297mm;
}

.print-title {
  margin: 0 0 0.75rem;
  font-size: 1.35rem;
  font-weight: 700;
  letter-spacing: 0.01em;
}

.print-meta {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.35rem 1.25rem;
  margin: 0 0 1rem;
  font-size: 0.82rem;
}

.print-meta dt {
  font-size: 0.68rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #444;
}

.print-meta dd {
  margin: 0;
  font-weight: 600;
}

.print-table {
  width: 100%;
  border-collapse: collapse;
  table-layout: auto;
  font-size: 0.72rem;
}

.print-table th,
.print-table td {
  border: 1px solid #222;
  padding: 0.28rem 0.32rem;
  vertical-align: middle;
  background: #fff;
  color: #111;
}

.print-table thead th {
  background: #f3f3f3;
  font-weight: 700;
  text-align: center;
}

.print-table thead {
  display: table-header-group;
}

.print-table tbody tr {
  page-break-inside: avoid;
  break-inside: avoid;
}

.print-col-order,
.print-col-round,
.print-col-score,
.print-col-result {
  width: 2.2rem;
}

.print-col-set {
  white-space: nowrap;
}

.print-col-notes {
  width: 5.5rem;
}

.print-center,
.print-score-cell,
.print-result-cell {
  text-align: center;
}

.print-score-cell,
.print-result-cell,
.print-notes-cell {
  height: 1.85rem;
  min-width: 1.6rem;
}

.print-name {
  white-space: pre-line;
  overflow-wrap: anywhere;
  word-break: break-word;
}

.print-missing-ref {
  display: block;
  text-align: center;
  font-weight: 700;
}

.print-ref-line {
  display: block;
  border-bottom: 1px solid #111;
  min-width: 4.5rem;
  height: 0.85rem;
  margin-top: 0.1rem;
}

.print-footer {
  margin-top: 0.9rem;
  font-size: 0.78rem;
}

.print-footer p {
  margin: 0 0 0.35rem;
}

.print-notes-block {
  border: 1px solid #222;
  min-height: 2.6rem;
  margin-bottom: 0.75rem;
}

.print-sign {
  margin-top: 0.5rem;
}

@media screen {
  .print-sheet {
    box-shadow: 0 1px 10px rgb(0 0 0 / 0.12);
  }
}

@media (min-width: 720px) {
  .print-meta {
    grid-template-columns: repeat(5, minmax(0, 1fr));
  }
}

@media print {
  .print-sheet {
    max-width: none;
    margin: 0;
    padding: 0;
    box-shadow: none;
  }

  .print-table th,
  .print-table td {
    background: #fff !important;
    color: #111 !important;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }
}
</style>
