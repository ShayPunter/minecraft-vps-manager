<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { FolderGit2, HardDrive, LayoutGrid, Server, Users } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as adminServers } from '@/routes/admin/servers';
import { index as adminUsers } from '@/routes/admin/users';
import { edit as adminWorldStorage } from '@/routes/admin/world-storage';
import type { NavItem } from '@/types';

const page = usePage();

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    ...(page.props.auth.isAdmin
        ? [
              { title: 'Servers', href: adminServers(), icon: Server },
              { title: 'Users', href: adminUsers(), icon: Users },
              {
                  title: 'World storage',
                  href: adminWorldStorage(),
                  icon: HardDrive,
              },
          ]
        : []),
]);

const repositoryUrl = 'https://github.com/ShayPunter/minecraft-vps-manager';

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: repositoryUrl,
        icon: FolderGit2,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
