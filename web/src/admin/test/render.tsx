import { configure, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes, type InitialEntry } from 'react-router'
import { vi } from 'vitest'
import AdminApp from '../AdminApp'
import { createAdminQueryClient } from '../api/queryClient'
import { LocationProbe } from './LocationProbe'

// Rendus complets du dashboard (mise en page + page) : marges confortables pour les
// machines de CI ou de développement chargées. Aucun délai n'est attendu artificiellement.
configure({ asyncUtilTimeout: 10_000 })
vi.setConfig({ testTimeout: 60_000 })

/** Monte le dashboard comme dans src/main.tsx (sous /admin/*), avec un routeur mémoire. */
export function renderAdmin(entry: InitialEntry) {
  const user = userEvent.setup()
  const queryClient = createAdminQueryClient()
  const utils = render(
    <MemoryRouter initialEntries={[entry]}>
      <Routes>
        <Route
          path="/admin/*"
          element={
            <>
              <AdminApp queryClient={queryClient} />
              <LocationProbe />
            </>
          }
        />
      </Routes>
    </MemoryRouter>,
  )
  return {
    user,
    queryClient,
    ...utils,
    location: () => screen.getByTestId('location').textContent ?? '',
  }
}
