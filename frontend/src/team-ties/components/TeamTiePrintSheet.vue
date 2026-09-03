<script setup>
import { computed } from 'vue'

import { BYE_BADGE_LABEL } from '../../brackets/constants/bracketLabels'
import { printCompetitionTypeLabel } from '../../groups/utils/groupPrint'
import {
  printRubberSideLabel,
  printRubberStatusLabel,
  printShouldShowScore,
} from '../utils/teamTiePrint'

const props = defineProps({
  sheet: {
    type: Object,
    required: true,
  },
})

const isBye = computed(() => props.sheet?.team_tie?.is_bye === true)
const rubbers = computed(() => (Array.isArray(props.sheet?.rubbers) ? props.sheet.rubbers : []))
const hasRubbers = computed(() => rubbers.value.length > 0)
const showScore = computed(() => printShouldShowScore(props.sheet))
const isFinished = computed(() => props.sheet?.team_tie?.status === 'finished')
const winnerName = computed(() => props.sheet?.winner?.display_name || '')
const matchupLabel = computed(() => {
  const side1 = props.sheet?.side1?.display_name || 'Equipo 1'
  const side2 = props.sheet?.side2?.display_name

  if (isBye.value || !side2) {
    return side1
  }

  return `${side1} vs ${side2}`
})

const isWinnerSide = (rubber, sideNumber) => Number(rubber?.winner_side) === sideNumber
</script>

<template>
  <article class="team-tie-print-sheet print-sheet">
    <header class="print-header">
      <h1 class="print-title">{{ matchupLabel }}</h1>
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
        <div v-if="sheet.team_tie?.context_label">
          <dt>Contexto</dt>
          <dd>{{ sheet.team_tie.context_label }}</dd>
        </div>
      </dl>

      <template v-if="!isBye">
        <p v-if="sheet.format?.name" class="print-format">
          Formato: {{ sheet.format.name }}
        </p>
        <p v-if="sheet.team_tie?.victories_required" class="print-format-rule">
          Gana el primero en llegar a {{ sheet.team_tie.victories_required }} victorias
        </p>
        <p v-if="showScore" class="print-score">
          {{ sheet.side1?.display_name || 'Equipo 1' }}
          {{ sheet.score?.side1 ?? 0 }} — {{ sheet.score?.side2 ?? 0 }}
          {{ sheet.side2?.display_name || 'Equipo 2' }}
        </p>
        <p v-if="isFinished && winnerName" class="print-winner">
          Ganador: {{ winnerName }}
        </p>
      </template>
    </header>

    <section v-if="isBye" class="print-bye">
      <p class="print-bye-team">{{ sheet.side1?.display_name || 'Equipo' }}</p>
      <p class="print-bye-badge">{{ BYE_BADGE_LABEL }}</p>
    </section>

    <p v-else-if="!hasRubbers" class="print-empty">
      Los partidos internos aún no fueron generados.
    </p>

    <table v-else class="print-table">
      <thead>
        <tr>
          <th class="print-col-order">#</th>
          <th class="print-col-type">Tipo</th>
          <th>{{ sheet.side1?.display_name || 'Equipo A' }}</th>
          <th>{{ sheet.side2?.display_name || 'Equipo B' }}</th>
          <th class="print-col-status">Estado</th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="rubber in rubbers"
          :key="rubber.slot_order"
          :class="{
            'print-row--secondary': rubber.status === 'not_needed' || (rubber.status === 'finished' && !rubber.official),
          }"
        >
          <td class="print-center">{{ rubber.slot_order }}</td>
          <td>{{ rubber.label }}</td>
          <td>
            <span
              class="print-name"
              :class="{ 'print-name--winner': rubber.official && isWinnerSide(rubber, 1) }"
            >
              {{ printRubberSideLabel(rubber.side1) }}
            </span>
            <span
              v-if="isWinnerSide(rubber, 1)"
              class="print-check"
              :class="{ 'print-check--faint': !rubber.official }"
            >
              ✓
            </span>
          </td>
          <td>
            <span
              class="print-name"
              :class="{ 'print-name--winner': rubber.official && isWinnerSide(rubber, 2) }"
            >
              {{ printRubberSideLabel(rubber.side2) }}
            </span>
            <span
              v-if="isWinnerSide(rubber, 2)"
              class="print-check"
              :class="{ 'print-check--faint': !rubber.official }"
            >
              ✓
            </span>
          </td>
          <td class="print-center print-status">{{ printRubberStatusLabel(rubber) }}</td>
        </tr>
      </tbody>
    </table>
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
  margin: 0 0 0.85rem;
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

.print-format,
.print-format-rule,
.print-score,
.print-winner {
  margin: 0 0 0.25rem;
  font-size: 0.88rem;
}

.print-score {
  font-weight: 700;
  margin-top: 0.55rem;
}

.print-winner {
  font-weight: 700;
}

.print-bye {
  margin-top: 1.25rem;
  border: 1px solid #222;
  padding: 1rem;
  text-align: center;
}

.print-bye-team {
  margin: 0 0 0.35rem;
  font-size: 1.15rem;
  font-weight: 700;
}

.print-bye-badge {
  margin: 0;
  font-size: 0.95rem;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.print-empty {
  margin: 1rem 0 0;
  font-size: 0.9rem;
}

.print-table {
  width: 100%;
  border-collapse: collapse;
  margin-top: 0.9rem;
  font-size: 0.78rem;
}

.print-table th,
.print-table td {
  border: 1px solid #222;
  padding: 0.4rem 0.45rem;
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
.print-col-status {
  width: 12%;
}

.print-col-type {
  width: 18%;
}

.print-center {
  text-align: center;
}

.print-name--winner {
  font-weight: 700;
}

.print-check {
  margin-left: 0.3rem;
  font-weight: 700;
}

.print-check--faint,
.print-row--secondary td {
  color: #666;
}

.print-row--secondary td {
  background: #f7f7f7;
}

.print-status {
  white-space: nowrap;
}

@media print {
  .print-sheet {
    max-width: none;
    padding: 0;
    box-shadow: none;
  }

  .print-table th,
  .print-table td {
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }
}
</style>
