import { setupLayouts } from 'virtual:meta-layouts'
import { createRouter, createWebHistory } from 'vue-router/auto'

function recursiveLayouts(route) {
  if (route.children) {
    for (let i = 0; i < route.children.length; i++)
      route.children[i] = recursiveLayouts(route.children[i])
    
    return route
  }
  
  return setupLayouts([route])[0]
}

const router = createRouter({
  history: createWebHistory(import.meta.env.VITE_ROUTER_BASE || '/'),
  scrollBehavior(to) {
    if (to.hash)
      return { el: to.hash, behavior: 'smooth', top: 60 }
    
    return { top: 0 }
  },
  extendRoutes: pages => [
    ...[...pages].map(route => recursiveLayouts(route)),
  ],
})

router.beforeEach(to => {
  const hasToken = Boolean(localStorage.getItem('hsc_admin_token'))
  const hasMemberToken = Boolean(localStorage.getItem('hsc_member_token'))

  if (to.meta.memberOnly && !hasMemberToken)
    return { name: 'member-login' }

  if (to.meta.memberGuest && hasMemberToken)
    return { name: 'member-portal' }

  if (!to.meta.public && !to.meta.memberOnly && !hasToken)
    return { name: 'login', query: { redirect: to.fullPath } }

  if (to.name === 'login' && hasToken)
    return { name: 'root' }
})

export { router }
export default function (app) {
  app.use(router)
}
