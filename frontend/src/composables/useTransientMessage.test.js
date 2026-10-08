import assert from 'node:assert/strict'
import { afterEach, describe, it, mock } from 'node:test'
import { effectScope } from 'vue'

import { useTransientMessage } from './useTransientMessage.js'

const mount = () => {
  const scope = effectScope()
  let api

  scope.run(() => {
    api = useTransientMessage(3500)
  })

  return { scope, api }
}

describe('useTransientMessage', () => {
  afterEach(() => {
    mock.timers.reset()
  })

  it('borra el mensaje a los 3,5 s', () => {
    mock.timers.enable({ apis: ['setTimeout'] })
    const { scope, api } = mount()

    api.show('Listo')
    assert.equal(api.message, 'Listo')
    mock.timers.tick(3499)
    assert.equal(api.message, 'Listo')
    mock.timers.tick(1)
    assert.equal(api.message, '')

    scope.stop()
  })

  it('un mensaje nuevo reemplaza al anterior y reinicia el tiempo', () => {
    mock.timers.enable({ apis: ['setTimeout'] })
    const { scope, api } = mount()

    api.show('Primero')
    mock.timers.tick(3000)
    api.show('Segundo')
    mock.timers.tick(3000)
    assert.equal(api.message, 'Segundo')
    mock.timers.tick(500)
    assert.equal(api.message, '')

    scope.stop()
  })

  it('al destruir el scope no queda un timer que pise un mensaje posterior', () => {
    mock.timers.enable({ apis: ['setTimeout'] })
    const { scope, api } = mount()

    api.show('Listo')
    scope.stop()
    api.message = 'Sigue'
    mock.timers.tick(3500)
    assert.equal(api.message, 'Sigue')
  })
})
