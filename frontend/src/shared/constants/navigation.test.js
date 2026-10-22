import assert from 'node:assert/strict'
import { describe, it } from 'node:test'

import { NAVIGATION_LINKS, NAV_LABEL_RATING_SYSTEMS } from './navigation.js'

describe('navegación de sistemas de rating', () => {
  it('exige ratings.manage y no queda visible para el resto', () => {
    const link = NAVIGATION_LINKS.find((item) => item.to === '/rating-systems')

    assert.ok(link)
    assert.equal(link.name, NAV_LABEL_RATING_SYSTEMS)
    assert.equal(link.permission, 'ratings.manage')
  })
})
