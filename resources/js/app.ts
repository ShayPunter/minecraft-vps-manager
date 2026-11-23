import { createInertiaApp } from '@inertiajs/vue3';
import { defineAsyncComponent } from 'vue';
import { initializeTheme } from '@/composables/useAppearance';
import { initializeFlashToast } from '@/lib/flashToast';

// Their own chunks (#5753): the guest pages (/ and /s/{token}, opened on phones) have no
// layout and don't download the sidebar shell. Defined once, so every page that uses a
// layout gets the same component and Inertia keeps it mounted across visits.
const AppLayout = defineAsyncComponent(() => import('@/layouts/AppLayout.vue'));
const AuthLayout = defineAsyncComponent(
    () => import('@/layouts/AuthLayout.vue'),
);
const SettingsLayout = defineAsyncComponent(
    () => import('@/layouts/settings/Layout.vue'),
);

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            // No sidebar for guests: the share page (#5462) is laid out like Welcome.
            case name === 'Welcome' || name === 'Shared':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    withApp: (app) => {
        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
