import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';
import { configureEcho } from '@laravel/echo-vue';

configureEcho({
    broadcaster: 'reverb',
});

// The site name comes from the server (the `site_name` setting, shared as the
// Inertia `name` prop) so an admin's change shows in the browser title without
// a rebuild; VITE_APP_NAME is only the fallback if the prop is ever absent.
const fallbackAppName = import.meta.env.VITE_APP_NAME || 'Laravel';

void createInertiaApp({
    title: (title, page) => {
        const appName = (page.props.name as string | undefined) || fallbackAppName;

        return title ? `${title} - ${appName}` : appName;
    },
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
            case name.startsWith('tutors/'):
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
