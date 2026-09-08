<script setup>
import { computed } from 'vue'

import { BYE_BADGE_LABEL } from '../constants/bracketLabels'
import { formatGroupOriginLabel } from '../utils/formatGroupOriginLabel'
import {
  formatPrintDisplayName,
  printCompetitionTypeLabel,
} from '../../groups/utils/groupPrint'

const props = defineProps({
  sheet: {
    type: Object,
    required: true,
  },
})

const MATCH_CARD_HEIGHT = 64
const MATCH_GAP = 16
const MATCH_SLOT_HEIGHT = MATCH_CARD_HEIGHT + MATCH_GAP

const rounds = computed(() => props.sheet?.rounds ?? [])
const thirdPlace = computed(() => props.sheet?.third_place ?? null)
const champion = computed(() => props.sheet?.champion ?? null)
const showPlayoffThirdPlace = computed(
  () => thirdPlace.value?.mode === 'playoff' && (thirdPlace.value.side1 || thirdPlace.value.side1_placeholder),
)
const showSharedThirdPlace = computed(() => thirdPlace.value?.mode === 'shared')

const roundSlotHeight = (roundIndex) => MATCH_SLOT_HEIGHT * 2 ** roundIndex
const pairConnectorHeight = (roundIndex) => roundSlotHeight(roundIndex)
const isEvenMatchIndex = (matchIndex) => matchIndex % 2 === 0
const hasNextRound = (roundIndex) => roundIndex < rounds.value.length - 1

const sidePayload = (match, sideNumber) => (sideNumber === 1 ? match?.side1 : match?.side2)

const sidePlaceholder = (match, sideNumber) =>
  sideNumber === 1 ? match?.side1_placeholder : match?.side2_placeholder

const sideText = (match, sideNumber) => {
  const displayName = sidePayload(match, sideNumber)?.display_name

  if (displayName) {
    return formatPrintDisplayName(displayName)
  }

  return sidePlaceholder(match, sideNumber) || ''
}

const sideOriginLabel = (match, sideNumber) =>
  formatGroupOriginLabel(sidePayload(match, sideNumber)?.group_origin)

const isWinnerSide = (match, sideNumber) => {
  const winnerId = match?.winner?.competition_entry_id
  const sideId = sidePayload(match, sideNumber)?.competition_entry_id

  return Boolean(winnerId && sideId && Number(winnerId) === Number(sideId))
}

const isPlaceholderSide = (match, sideNumber) =>
  !sidePayload(match, sideNumber)?.display_name && Boolean(sidePlaceholder(match, sideNumber))

const isFinalRound = (round) => round?.label === 'Final'
</script>

<template>
  <article class="bracket-print-sheet">
    <header class="print-header">
      <h1 class="print-title">Llave eliminatoria</h1>
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
          <dt>Llave</dt>
          <dd>de {{ sheet.bracket?.bracket_size ?? '—' }}</dd>
        </div>
      </dl>
    </header>

    <div class="bracket-tree">
      <section
        v-for="(round, roundIndex) in rounds"
        :key="`${round.number}-${round.label}`"
        class="bracket-round"
      >
        <h2 class="round-label">{{ round.label }}</h2>

        <div
          v-if="isFinalRound(round) && champion"
          class="champion-badge"
        >
          <span class="champion-kicker">Campeón</span>
          <span class="champion-name">{{ formatPrintDisplayName(champion.display_name) }}</span>
        </div>

        <ul class="round-matches">
          <li
            v-for="(match, matchIndex) in round.matches"
            :key="`${round.number}-${match.match_number}`"
            class="match-slot"
            :style="{ height: `${roundSlotHeight(roundIndex)}px` }"
          >
            <article
              class="match-card"
              :class="{
                'match-card--final': isFinalRound(round),
                'match-card--bye': match.is_bye,
              }"
            >
              <template v-if="match.is_bye">
                <p class="side-row side-row--winner">
                  <span class="side-identity">
                    <span class="side-name">{{ sideText(match, 1) }}</span>
                    <span v-if="sideOriginLabel(match, 1)" class="side-origin">{{ sideOriginLabel(match, 1) }}</span>
                  </span>
                </p>
                <p class="bye-caption">{{ BYE_BADGE_LABEL }}</p>
              </template>
              <template v-else>
                <p
                  class="side-row"
                  :class="{
                    'side-row--winner': isWinnerSide(match, 1),
                    'side-row--placeholder': isPlaceholderSide(match, 1),
                  }"
                >
                  <span class="side-identity">
                    <span class="side-name">{{ sideText(match, 1) }}</span>
                    <span v-if="sideOriginLabel(match, 1)" class="side-origin">{{ sideOriginLabel(match, 1) }}</span>
                  </span>
                  <span v-if="isWinnerSide(match, 1)" class="winner-mark">✓</span>
                </p>
                <p
                  class="side-row"
                  :class="{
                    'side-row--winner': isWinnerSide(match, 2),
                    'side-row--placeholder': isPlaceholderSide(match, 2),
                  }"
                >
                  <span class="side-identity">
                    <span class="side-name">{{ sideText(match, 2) }}</span>
                    <span v-if="sideOriginLabel(match, 2)" class="side-origin">{{ sideOriginLabel(match, 2) }}</span>
                  </span>
                  <span v-if="isWinnerSide(match, 2)" class="winner-mark">✓</span>
                </p>
              </template>
            </article>

            <span
              v-if="hasNextRound(roundIndex)"
              class="connector-h"
              aria-hidden="true"
            />

            <span
              v-if="hasNextRound(roundIndex) && isEvenMatchIndex(matchIndex) && round.matches[matchIndex + 1]"
              class="connector-pair"
              :style="{ height: `${pairConnectorHeight(roundIndex)}px` }"
              aria-hidden="true"
            >
              <span class="connector-v" />
              <span class="connector-next" />
            </span>
          </li>
        </ul>
      </section>
    </div>

    <aside v-if="showPlayoffThirdPlace" class="third-place">
      <h2 class="third-place-title">{{ thirdPlace.label || 'Tercer puesto' }}</h2>
      <article class="match-card">
        <p
          class="side-row"
          :class="{
            'side-row--winner': isWinnerSide(thirdPlace, 1),
            'side-row--placeholder': isPlaceholderSide(thirdPlace, 1),
          }"
        >
          <span class="side-identity">
            <span class="side-name">{{ sideText(thirdPlace, 1) }}</span>
            <span v-if="sideOriginLabel(thirdPlace, 1)" class="side-origin">{{ sideOriginLabel(thirdPlace, 1) }}</span>
          </span>
          <span v-if="isWinnerSide(thirdPlace, 1)" class="winner-mark">✓</span>
        </p>
        <p
          class="side-row"
          :class="{
            'side-row--winner': isWinnerSide(thirdPlace, 2),
            'side-row--placeholder': isPlaceholderSide(thirdPlace, 2),
          }"
        >
          <span class="side-identity">
            <span class="side-name">{{ sideText(thirdPlace, 2) }}</span>
            <span v-if="sideOriginLabel(thirdPlace, 2)" class="side-origin">{{ sideOriginLabel(thirdPlace, 2) }}</span>
          </span>
          <span v-if="isWinnerSide(thirdPlace, 2)" class="winner-mark">✓</span>
        </p>
      </article>
    </aside>

    <p v-else-if="showSharedThirdPlace" class="shared-third">
      {{ thirdPlace.label }}
    </p>
  </article>
</template>

<style scoped>
.bracket-print-sheet {
  background: #fff;
  color: #111;
  padding: 8mm 8mm;
  max-width: 297mm;
  margin: 0 auto;
}

.print-title {
  margin: 0 0 0.5rem;
  font-size: 1.15rem;
  font-weight: 700;
}

.print-meta {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.25rem 1rem;
  margin: 0 0 0.85rem;
  font-size: 0.78rem;
}

.print-meta dt {
  font-size: 0.62rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #444;
}

.print-meta dd {
  margin: 0;
  font-weight: 600;
}

.bracket-tree {
  display: flex;
  align-items: flex-start;
  gap: 1.75rem;
  overflow: visible;
}

.bracket-round {
  width: 10.75rem;
  flex: 0 0 10.75rem;
}

.round-label {
  margin: 0 0 0.4rem;
  text-align: center;
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.champion-badge {
  margin: 0 auto 0.45rem;
  max-width: 100%;
  border: 1px solid #111;
  padding: 0.2rem 0.4rem;
  text-align: center;
}

.champion-kicker {
  display: block;
  font-size: 0.58rem;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: #333;
}

.champion-name {
  display: block;
  font-size: 0.78rem;
  font-weight: 700;
  white-space: pre-line;
}

.round-matches {
  margin: 0;
  padding: 0;
  list-style: none;
}

.match-slot {
  position: relative;
}

.match-card {
  position: absolute;
  inset-inline: 0;
  top: 50%;
  transform: translateY(-50%);
  border: 1px solid #222;
  background: #fff;
  padding: 0.2rem 0.35rem;
  min-height: 2.9rem;
}

.match-card--final {
  border-width: 1.5px;
}

.match-card--bye {
  min-height: 2.4rem;
}

.side-row {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.25rem;
  margin: 0;
  padding: 0.12rem 0;
  font-size: 0.72rem;
  line-height: 1.2;
}

.side-row + .side-row {
  border-top: 1px solid #ccc;
}

.side-row--winner {
  font-weight: 700;
}

.side-row--placeholder {
  color: #555;
  font-style: italic;
  font-weight: 400;
}

.side-identity {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 0.05rem;
}

.side-name {
  white-space: pre-line;
  overflow-wrap: anywhere;
  word-break: break-word;
}

.side-origin {
  font-size: 0.58rem;
  font-weight: 400;
  font-style: normal;
  color: #555;
  line-height: 1.15;
}

.winner-mark {
  flex-shrink: 0;
  font-size: 0.68rem;
}

.bye-caption {
  margin: 0.05rem 0 0;
  font-size: 0.62rem;
  font-style: italic;
  color: #444;
}

.connector-h {
  position: absolute;
  top: 50%;
  right: -1.75rem;
  width: 1.75rem;
  border-top: 1px solid #222;
}

.connector-pair {
  pointer-events: none;
  position: absolute;
  top: 50%;
  right: -1.75rem;
  width: 1.75rem;
}

.connector-v {
  position: absolute;
  top: 0;
  right: 0;
  height: 100%;
  border-left: 1px solid #222;
}

.connector-next {
  position: absolute;
  top: 50%;
  right: 0;
  width: 1.75rem;
  transform: translateX(100%);
  border-top: 1px solid #222;
}

.third-place {
  margin-top: 0.9rem;
  max-width: 12rem;
}

.third-place-title {
  margin: 0 0 0.35rem;
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.third-place .match-card {
  position: static;
  transform: none;
}

.shared-third {
  margin: 0.75rem 0 0;
  font-size: 0.78rem;
  font-style: italic;
}

@media (min-width: 720px) {
  .print-meta {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
}

@media screen {
  .bracket-print-sheet {
    box-shadow: 0 1px 10px rgb(0 0 0 / 0.12);
  }
}

@media print {
  .bracket-print-sheet {
    max-width: none;
    margin: 0;
    padding: 0;
    box-shadow: none;
    background: #fff;
    color: #111;
  }
}
</style>
