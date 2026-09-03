<script setup lang="ts">
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';

defineProps<{
    meta: {
        current_page: number;
        last_page: number;
        total: number;
        from: number;
        to: number;
    };
}>();

defineEmits(['page-change']);
</script>

<template>
    <div class="flex items-center justify-between px-2 py-4">
        <div class="text-sm text-muted-foreground">
            Showing {{ meta.from }} to {{ meta.to }} of {{ meta.total }} entries
        </div>
        <div class="flex items-center space-x-2">
            <Button variant="outline" size="sm" :disabled="meta.current_page === 1"
                @click="$emit('page-change', meta.current_page - 1)">
                <ChevronLeft class="h-4 w-4 mr-1" />
                Previous
            </Button>
            <div class="flex items-center justify-center text-sm font-medium">
                Page {{ meta.current_page }} of {{ meta.last_page }}
            </div>
            <Button variant="outline" size="sm" :disabled="meta.current_page === meta.last_page"
                @click="$emit('page-change', meta.current_page + 1)">
                Next
                <ChevronRight class="h-4 w-4 ml-1" />
            </Button>
        </div>
    </div>
</template>
