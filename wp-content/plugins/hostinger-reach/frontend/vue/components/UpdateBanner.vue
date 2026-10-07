<script setup lang="ts">
import { HNotificationRow } from '@hostinger/hcomponents';

import { useGeneralDataStore } from '@/stores/generalDataStore';
import { translate } from '@/utils/translate';

const generalDataStore = useGeneralDataStore();

const goToPluginsPage = (): void => {
	window.location.href = generalDataStore.pluginsPageUrl;
};
</script>

<template>
	<HNotificationRow
		v-if="generalDataStore.isUpdateAvailable"
		:show-close-icon="false"
		:label="translate('hostinger_reach_update_banner_title')"
		:description="translate('hostinger_reach_update_banner_description')"
		:primary-action-text="translate('hostinger_reach_update_banner_button')"
		variant="warning"
		:primary-action-props="{ iconAppend: 'ic-arrow-up-right-square-16', variant: 'contain', color: 'warning' }"
		@primary-action-click="goToPluginsPage"
	/>
</template>
