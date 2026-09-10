<script setup>
import { computed } from 'vue'

import {
  groupConsecutiveMatchesByRound,
  printBestOfLabel,
  printCompetitionTypeLabel,
  printSetColumns,
} from '../utils/groupPrint'
import GroupPrintMatchBlock from './GroupPrintMatchBlock.vue'

const props = defineProps({
  sheet: {
    type: Object,
    required: true,
  },
})

const setColumns = computed(() => printSetColumns(props.sheet?.best_of))
const matchRounds = computed(() => groupConsecutiveMatchesByRound(props.sheet?.matches))
const stackMatches = computed(() => Number(props.sheet?.best_of) < 5)
const matrixRows = computed(() => (Array.isArray(props.sheet?.matrix) ? props.sheet.matrix : []))
const matrixNumbers = computed(() => {
  const first = matrixRows.value[0]

  if (Array.isArray(first?.cells) && first.cells.length > 0) {
    return first.cells.map((cell) => cell.opponent_number)
  }

  return matrixRows.value.map((row) => row.sheet_number)
})
const notesLineCount = computed(() => {
  const kind = props.sheet?.sheet_kind

  if (kind === 'g5') {
    return 3
  }

  if (kind === 'g4') {
    return 4
  }

  return 5
})
</script>

<template>
  <div
    class="official-layout"
    :class="[
      `official-layout--${sheet.sheet_kind || 'g3'}`,
      `official-layout--bo${Number(sheet.best_of) || 3}`,
    ]"
  >
    <header class="official-header">
      <table class="header-table">
        <colgroup>
          <col class="header-col-label">
          <col class="header-col-value">
          <col class="header-col-label">
          <col class="header-col-blank">
        </colgroup>
        <tbody>
          <tr>
            <th>Torneo</th>
            <td>{{ sheet.tournament?.name || '—' }}</td>
            <th>Fecha</th>
            <td class="header-blank" />
          </tr>
          <tr>
            <th>Competencia</th>
            <td>{{ sheet.competition?.name || '—' }}</td>
            <th>Mesa</th>
            <td class="header-blank" />
          </tr>
          <tr>
            <th>Modalidad</th>
            <td>{{ printCompetitionTypeLabel(sheet.competition?.type) }}</td>
            <th>Día</th>
            <td class="header-blank" />
          </tr>
          <tr>
            <th>Grupo</th>
            <td>{{ sheet.group?.name || '—' }}</td>
            <th>Hora</th>
            <td class="header-blank" />
          </tr>
          <tr>
            <th>Formato</th>
            <td>{{ printBestOfLabel(sheet.best_of) }}</td>
            <th>Clasifican</th>
            <td>{{ sheet.qualified_per_group ?? '' }}</td>
          </tr>
        </tbody>
      </table>
    </header>

    <section class="official-matrix" aria-label="Matriz del grupo">
      <table class="matrix-table">
        <thead>
          <tr>
            <th class="matrix-col-num">Nº</th>
            <th class="matrix-col-name">Jugador / Pareja</th>
            <th class="matrix-col-assoc">Asociación</th>
            <th
              v-for="number in matrixNumbers"
              :key="`mh-${number}`"
              class="matrix-col-cell"
            >
              {{ number }}
            </th>
            <th class="matrix-col-pts">Puntos</th>
            <th class="matrix-col-pos">Posición</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="row in matrixRows"
            :key="row.sheet_number"
          >
            <td class="matrix-num">{{ row.sheet_number }}</td>
            <td class="matrix-name">{{ row.entry?.display_name || '—' }}</td>
            <td class="matrix-assoc" />
            <td
              v-for="cell in row.cells"
              :key="`${row.sheet_number}-${cell.opponent_number}`"
              class="matrix-cell"
              :class="{ 'matrix-cell--self': cell.type === 'self' }"
            >
              <span
                v-if="cell.type === 'self'"
                class="matrix-self-mark"
                aria-hidden="true"
              >✕</span>
            </td>
            <td class="matrix-empty" />
            <td class="matrix-empty" />
          </tr>
        </tbody>
      </table>
    </section>

    <section class="official-matches" aria-label="Partidos">
      <div
        v-for="(round, roundIndex) in matchRounds"
        :key="`round-${round.group_round ?? roundIndex}`"
        class="match-round"
        :class="{ 'match-round--pair': !stackMatches && round.matches.length > 1 }"
      >
        <GroupPrintMatchBlock
          v-for="(match, matchIndex) in round.matches"
          :key="match.order ?? `${roundIndex}-${matchIndex}`"
          :match="match"
          :set-columns="setColumns"
        />
      </div>
    </section>

    <section class="official-notes" aria-label="Observaciones">
      <h2>Observaciones</h2>
      <div class="notes-block">
        <span
          v-for="line in notesLineCount"
          :key="`note-${line}`"
          class="notes-line"
        />
      </div>
    </section>

    <footer class="official-footer">
      <div class="sign-row">
        <p><span>Firma:</span> <span class="sign-line" /></p>
        <p><span>Aclaración:</span> <span class="sign-line" /></p>
      </div>
      <p class="sign-role">Árbitro general / Responsable</p>
    </footer>
  </div>
</template>

<style scoped>
.official-layout {
  display: flex;
  flex-direction: column;
  flex: 1;
  gap: 0.55rem;
  font-size: 0.72rem;
  color: #111;
}

.official-layout--g4 {
  gap: 0.45rem;
  font-size: 0.68rem;
}

.official-layout--g5 {
  gap: 0.32rem;
  font-size: 0.62rem;
}

.official-layout--g5 .header-table th,
.official-layout--g5 .header-table td,
.official-layout--g5 .matrix-table th,
.official-layout--g5 .matrix-table td {
  padding: 0.08rem 0.14rem;
}

.official-layout--g5 .matrix-cell,
.official-layout--g5 .matrix-empty,
.official-layout--g5 .matrix-assoc {
  height: 1.02rem;
}

.header-table,
.matrix-table {
  width: 100%;
  max-width: 100%;
  border-collapse: collapse;
  table-layout: fixed;
  box-sizing: border-box;
}

.header-table th,
.header-table td,
.matrix-table th,
.matrix-table td {
  border: 1px solid #222;
  padding: 0.14rem 0.22rem;
  background: #fff;
  color: #111;
  vertical-align: middle;
  box-sizing: border-box;
}

.header-col-label {
  width: 22mm;
}

.header-col-blank {
  width: 32mm;
}

.header-table th {
  font-size: 0.62rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  background: #f3f3f3;
  white-space: nowrap;
}

.header-table td {
  font-weight: 600;
  overflow-wrap: anywhere;
  word-break: break-word;
}

.header-blank {
  height: 1.05rem;
}

.matrix-table {
  font-size: inherit;
}

.matrix-table thead th {
  background: #f3f3f3;
  font-weight: 700;
  text-align: center;
  font-size: 0.62rem;
  text-transform: uppercase;
  letter-spacing: 0.02em;
}

.matrix-col-num,
.matrix-num {
  width: 7mm;
  text-align: center;
  font-weight: 700;
}

.matrix-col-name,
.matrix-name {
  width: auto;
  min-width: 0;
}

.matrix-col-assoc {
  width: 20mm;
}

.matrix-col-cell {
  width: 9.5mm;
  text-align: center;
}

.matrix-col-pts,
.matrix-col-pos {
  text-align: center;
  white-space: nowrap;
  letter-spacing: 0;
}

.matrix-col-pts {
  width: 13mm;
}

.matrix-col-pos {
  width: 16mm;
}

.official-layout--g5 .matrix-col-assoc {
  width: 15mm;
}

.official-layout--g5 .matrix-col-cell {
  width: 8mm;
}

.official-layout--g5 .matrix-col-pts {
  width: 12mm;
}

.official-layout--g5 .matrix-col-pos {
  width: 15mm;
}

.matrix-name {
  white-space: pre-line;
  overflow-wrap: anywhere;
  word-break: break-word;
  line-height: 1.15;
}

.matrix-cell,
.matrix-empty,
.matrix-assoc {
  height: 1.25rem;
}

.matrix-cell {
  text-align: center;
}

.matrix-cell--self {
  background:
    repeating-linear-gradient(
      -45deg,
      #d0d0d0,
      #d0d0d0 1px,
      #ececec 1px,
      #ececec 4px
    );
  -webkit-print-color-adjust: exact;
  print-color-adjust: exact;
}

.matrix-self-mark {
  font-size: 0.72rem;
  font-weight: 700;
  color: #444;
  line-height: 1;
}

.official-matches {
  display: flex;
  flex-direction: column;
  gap: 0.28rem;
}

.match-round {
  display: grid;
  grid-template-columns: 1fr;
  gap: 0.28rem;
  page-break-inside: avoid;
  break-inside: avoid;
}

.match-round--pair {
  grid-template-columns: 1fr 1fr;
}

.official-layout--g4 .official-matches,
.official-layout--g4 .match-round {
  gap: 0.22rem;
}

.official-layout--g5 .official-matches,
.official-layout--g5 .match-round {
  gap: 0.12rem;
}

.official-notes {
  margin-top: auto;
}

.official-notes h2 {
  margin: 0 0 0.15rem;
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.notes-block {
  border: 1px solid #222;
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
  min-height: 18mm;
  padding: 0 0.3rem;
}

.official-layout--g4 .notes-block {
  min-height: 14mm;
}

.official-layout--g5 .notes-block {
  min-height: 11mm;
}

.notes-line {
  display: block;
  border-bottom: 1px solid #c4c4c4;
  height: 5mm;
}

.official-footer {
  margin-top: 0.1rem;
}

.sign-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.75rem;
  font-size: 0.72rem;
}

.sign-row p {
  display: flex;
  align-items: flex-end;
  gap: 0.4rem;
  margin: 0;
}

.sign-line {
  flex: 1;
  border-bottom: 1px solid #111;
  height: 0.95rem;
}

.sign-role {
  margin: 0.2rem 0 0;
  font-size: 0.62rem;
  color: #444;
}

.official-layout--g3 :deep(.match-empty) {
  height: 1.4rem;
}

.official-layout--g5 :deep(.match-block th),
.official-layout--g5 :deep(.match-block td) {
  padding: 0.06rem 0.12rem;
}

.official-layout--g5 :deep(.match-empty) {
  height: 0.95rem;
}

.official-layout--g5 :deep(.match-col-result) {
  width: 16mm;
}

.official-layout--g5 :deep(.match-col-set) {
  width: 8.5mm;
}

.official-layout--g5 :deep(.match-col-ref) {
  width: 32mm;
}

.official-layout--bo5 .match-round--pair :deep(.match-col-result),
.official-layout--bo7 .match-round--pair :deep(.match-col-result) {
  width: 16mm;
}

.official-layout--bo5 .match-round--pair :deep(.match-col-set),
.official-layout--bo7 .match-round--pair :deep(.match-col-set) {
  width: 8mm;
}

.official-layout--bo5 .match-round--pair :deep(.match-col-ref),
.official-layout--bo7 .match-round--pair :deep(.match-col-ref) {
  width: 26mm;
}

@media print {
  .header-table th,
  .header-table td,
  .matrix-table th,
  .matrix-table td {
    color: #111 !important;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }

  .header-table th,
  .matrix-table thead th {
    background: #f3f3f3 !important;
  }

  .matrix-cell--self {
    background:
      repeating-linear-gradient(
        -45deg,
        #d0d0d0,
        #d0d0d0 1px,
        #ececec 1px,
        #ececec 4px
      ) !important;
  }
}
</style>
