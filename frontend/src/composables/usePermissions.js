import { storeToRefs } from 'pinia'

import { useAuthStore } from '../stores/auth'

export function usePermissions() {
  const authStore = useAuthStore()
  const { permissions, roles } = storeToRefs(authStore)

  const can = (permission) => authStore.hasPermission(permission)

  const canAny = (permissionList) => authStore.hasAnyPermission(permissionList)

  const canAll = (permissionList) => {
    if (!Array.isArray(permissionList) || permissionList.length === 0) {
      return false
    }

    return permissionList.every((permission) => authStore.hasPermission(permission))
  }

  const hasRole = (role) => authStore.hasRole(role)

  return {
    permissions,
    roles,
    can,
    canAny,
    canAll,
    hasRole,
  }
}
