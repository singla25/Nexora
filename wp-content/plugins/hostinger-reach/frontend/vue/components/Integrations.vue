<script setup lang="ts">
import { computed } from 'vue';

import Banner from '@/components/Banner.vue';
import PluginEntriesTable from '@/components/PluginEntriesTable.vue';
import { useModal } from '@/composables';
import { TABS_KEYS } from '@/data/tabs';
import { useIntegrationsStore } from '@/stores/integrationsStore';
import { ModalName } from '@/types';
import type { Form, Integration } from '@/types/models';
import { translate } from '@/utils/translate';

type IntegrationsProps = {
	onBannerButtonClick?: () => void;
};

const props = defineProps<IntegrationsProps>();

const emit = defineEmits({
	goToPlugin: (_id: string) => true,
	disconnectPlugin: (_id: string) => true,
	toggleFormStatus: (_form: Form, _status: boolean) => true,
	viewForm: (_form: Form) => true,
	editForm: (_form: Form) => true,
	addForm: (_id: string) => true
});

const { openModal } = useModal();
const integrationsStore = useIntegrationsStore();

const shouldShowTable = computed(
	() =>
		integrationsStore?.activeIntegrations?.filter((integration) => integration.type === TABS_KEYS.OVERVIEW_TAB_FORMS)
			?.length > 1 || integrationsStore.hasAnyForms(TABS_KEYS.OVERVIEW_TAB_FORMS)
);

const connectPlugin = async (integration: Integration) => {
	const connected = await integrationsStore.toggleIntegrationStatus(integration.id, true);
	const hasContacts = integration.importEnabled && integration.importStatus?.total > 0;
	if (connected && hasContacts) {
		openModal(
			ModalName.SYNC_CONTACTS_MODAL,
			{
				title: translate('hostinger_reach_contacts_modal_title'),
				subtitle: translate('hostinger_reach_contacts_modal_subtitle'),
				data: { integrations: [integration] }
			},
			{ hasCloseButton: true }
		);
	}
};

const onMoreClick = async () => {
	openModal(ModalName.CONNECT_PLUGIN_MODAL, {}, { hasCloseButton: true, isLG: true });
};

const isBannerVisible = computed(() => !integrationsStore.isLoading && !shouldShowTable.value);
const isTableVisible = computed(() => integrationsStore.isLoading || shouldShowTable.value);
const recommendedPlugins = computed(() => integrationsStore.recommendedPlugins);
</script>

<template>
	<div class="integrations">
		<Banner
			v-if="isBannerVisible"
			:title="translate(`hostinger_reach_forms_banner_title`)"
			:description="translate(`hostinger_reach_forms_banner_description`)"
			:button-text="translate(`hostinger_reach_forms_banner_button_text`)"
			:on-button-click="props.onBannerButtonClick"
			:is-button-loading="integrationsStore.isLoading"
		>
			<template #extra>
				<div class="divider-text">
					<span>{{ translate('hostinger_reach_forms_banner_connect_text') }}</span>
				</div>
				<div v-if="recommendedPlugins.length" class="recommended-plugins-wrap" role="list">
					<div class="recommended-plugins" role="list">
						<button
							v-for="plugin in recommendedPlugins"
							:key="plugin.id"
							class="recommended-plugins__pill"
							type="button"
							role="listitem"
							:aria-label="plugin.title"
							@click="connectPlugin(plugin)"
						>
							<span class="recommended-plugins__icon-wrap" aria-hidden="true">
								<img class="recommended-plugins__icon" :src="plugin.icon" :alt="plugin.title" loading="lazy" />
							</span>
							<span class="recommended-plugins__name">
								{{ plugin.title }}
							</span>
						</button>
						<HButton
							size="small"
							color="primary"
							variant="text"
							icon-prepend="ic-magnifying-glass-16"
							class="recommended-plugins-more"
							@click="onMoreClick"
						>
							{{ translate('hostinger_reach_forms_banner_more_text') }}
						</HButton>
					</div>
				</div>
			</template>
		</Banner>

		<PluginEntriesTable
			v-if="isTableVisible"
			:integrations="integrationsStore.fromType(TABS_KEYS.OVERVIEW_TAB_FORMS) as Integration[]"
			:is-loading="integrationsStore.isLoading"
			@go-to-plugin="emit('goToPlugin', $event)"
			@disconnect-plugin="emit('disconnectPlugin', $event)"
			@toggle-form-status="(form, status) => emit('toggleFormStatus', form, status)"
			@view-form="emit('viewForm', $event)"
			@edit-form="emit('editForm', $event)"
			@add-form="emit('addForm', $event)"
		/>
	</div>
</template>

<style scoped lang="scss">
.integrations {
	display: flex;
	flex-direction: column;
	align-self: stretch;
	gap: 20px;
	margin-bottom: 40px;
}

.divider-text {
	display: flex;
	align-items: center;
	gap: 16px;
	width: 100%;
	color: #6b7280;
	font-size: 16px;
	line-height: 1.2;
}

.divider-text::before,
.divider-text::after {
	content: '';
	flex: 1;
	height: 1px;
	width: 175px;
	background: #dadce0;
}

.divider-text > span {
	white-space: nowrap;
	font-weight: 400;
	font-size: 12px;
	line-height: 1.67;
}

.recommended-plugins {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 12px;
	flex-wrap: nowrap;
	width: 100%;
	margin-top: 4px;

	@media (max-width: 992px) {
		flex-wrap: wrap;
	}
}

.recommended-plugins__pill {
	display: inline-flex;
	align-items: center;
	gap: 5px;
	padding: 5px 15px;
	background: var(--neutral--0);
	border: 1px solid var(--neutral--200);
	border-radius: 14px;
	cursor: pointer;
	transition:
		border-color 0.2s ease,
		box-shadow 0.2s ease;

	&:hover {
		border-color: var(--neutral--300);
	}

	&:focus-visible {
		outline: none;
		box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.15);
	}
}

.recommended-plugins-wrap {
	margin-top: 20px;
	display: flex;
	align-items: center;
}

.recommended-plugins__icon-wrap {
	width: 28px;
	height: 28px;
	border-radius: 999px;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	background: var(--neutral--50);
	flex: 0 0 auto;
}

.recommended-plugins__icon {
	width: 18px;
	height: 18px;
	object-fit: contain;
}

.recommended-plugins__name {
	font-weight: 600;
	font-size: 12px;
	line-height: 1.2;
	color: var(--neutral--600);
	white-space: nowrap;
}
</style>
