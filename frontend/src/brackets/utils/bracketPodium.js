export function matchPair(side1, side2, winnerSide) {
  if (winnerSide !== 1 && winnerSide !== 2) {
    return null
  }

  return {
    winner: winnerSide === 1 ? side1 : side2,
    loser: winnerSide === 1 ? side2 : side1,
  }
}

export function buildBracketPodium(finalPair, thirdPair = null) {
  if (!finalPair?.winner) {
    return null
  }

  return {
    champion: finalPair.winner,
    runnerUp: finalPair.loser,
    third: thirdPair?.winner ?? null,
    fourth: thirdPair?.loser ?? null,
  }
}
