<script setup lang="ts">
import { HIcon, HPopover } from '@hostinger/hcomponents';
import { computed } from 'vue';

import Toggle from '@/components/Toggle.vue';
import { useIntegrationsStore } from '@/stores';
import type { Form, Integration } from '@/types/models';
import { translate } from '@/utils/translate';

const { syncContacts } = useIntegrationsStore();

interface Props {
	form: Form;
	integration: Integration;
}

const props = defineProps<Props>();

const emit = defineEmits<{
	toggleStatus: [form: Form, status: boolean];
	viewForm: [form: Form];
	editForm: [form: Form];
}>();

const handleSync = async (integration: Integration, form: Form) => {
	await syncContacts({ [integration.id]: new Set([form.formId]) });
};

const pluginTitle = computed(
	() => props.form.formTitle || props.form.post?.postTitle || translate('hostinger_reach_forms_no_title')
);

const supportsImport = computed(() => props.integration.type !== 'ecommerce' && props.integration.importEnabled);
const hasActions = computed(() => !props.integration.isViewFormHidden || !props.integration.isEditFormHidden);
const shouldHideToggle = computed(
	() =>
		!props.integration.canToggleForms ||
		(props.integration.id === 'elementor' && !props.form.formId?.startsWith('elementor-hostinger-reach-form'))
);
</script>

<template>
	<div class="form-item">
		<div class="form-item__cell form-item__cell--plugin">
			<div class="form-item__form-content">
				<div class="form-item__form-info">
					<span class="form-item__form-title">
						{{ pluginTitle }}
					</span>
				</div>
			</div>
		</div>
		<div class="form-item__cell form-item__cell--forms">
			<span class="form-item__mobile-label">
				{{ translate('hostinger_reach_plugin_entries_table_syncing_header') }}:
			</span>
			<Toggle
				v-if="shouldHideToggle"
				v-tooltip="{
					content: translate('hostinger_reach_plugin_cannot_disable'),
					placement: 'top'
				}"
				:value="props.form.isActive"
				:is-disabled="true"
				@toggle="(status) => emit('toggleStatus', props.form, status)"
			/>
			<Toggle
				v-else
				:value="props.form.isActive"
				:is-disabled="form.isLoading"
				@toggle="(status) => emit('toggleStatus', props.form, status)"
			/>
		</div>
		<div class="form-item__cell form-item__cell--actions">
			<HPopover
				v-if="hasActions"
				placement="bottom-end"
				:show-arrow="false"
				background-color="neutral--0"
				border-radius="12px"
				:outside-click-enabled="true"
			>
				<template #trigger>
					<button class="form-item__action-button">
						<HIcon name="ic-dots-vertical-16" />
					</button>
				</template>
				<div class="form-item__popover-menu">
					<div v-if="!integration.isViewFormHidden" class="form-item__menu-item" @click="emit('viewForm', props.form)">
						<HIcon name="ic-arrow-up-right-square-16" />
						<span>{{ translate('hostinger_reach_plugin_entries_table_view_form') }}</span>
					</div>
					<div v-if="!integration.isEditFormHidden" class="form-item__menu-item" @click="emit('editForm', props.form)">
						<HIcon name="ic-edit-16" />
						<span>{{ translate('hostinger_reach_plugin_entries_table_edit_form') }}</span>
					</div>
					<div
						v-if="supportsImport && props.integration.id !== 'thrive-leads'"
						class="form-item__menu-item"
						@click="handleSync(props.integration, props.form)"
					>
						<HIcon name="ic-arrows-circle-16" />
						<span>{{ translate('hostinger_reach_sync_contacts_button_text') }}</span>
					</div>
				</div>
			</HPopover>
		</div>
	</div>
</template>

<style scoped lang="scss">
.form-item {
	display: flex;
	align-items: center;
	border-top: 1px solid var(--neutral--200);

	&:first-child {
		border-top: none;
	}

	&__cell {
		display: flex;
		align-items: center;

		&--plugin {
			width: 40%;
		}

		&--forms {
			width: 27%;
		}

		&--actions {
			width: 40%;
			display: flex;
			justify-content: flex-end;
		}
	}

	&__form-content {
		display: flex;
		align-items: center;
		gap: 12px;
	}

	&__form-info {
		display: flex;
		flex-direction: column;
		gap: 2px;
	}

	&__form-title {
		font-weight: 500;
		font-size: 14px;
		color: var(--neutral--600);
	}

	&__status-label {
		font-size: 12px;
	}

	&__mobile-label {
		font-weight: 500;
		font-size: 14px;
		color: var(--neutral--600);
		margin-right: 8px;
		display: none;
	}

	&__action-button {
		background: var(--neutral--0);
		border: 1px solid var(--neutral--200);
		border-radius: 8px;
		padding: 0 8px;
		height: 32px;
		display: flex;
		align-items: center;
		justify-content: center;
		cursor: pointer;
		transition: all 0.2s ease;

		&:hover {
			border-color: var(--neutral--300);
		}
	}

	&__popover-menu {
		padding: 4px;
		min-width: 180px;
	}

	&__menu-item {
		display: flex;
		align-items: center;
		gap: 8px;
		padding: 12px;
		cursor: pointer;
		border-radius: 8px;
		font-weight: 500;
		font-size: 14px;
		color: var(--neutral--600);
		transition: background-color 0.2s ease;

		&:hover {
			background-color: var(--neutral--50);
		}

		span {
			flex: 1;
		}
	}

	@media (max-width: 1023px) {
		flex-direction: column;
		gap: 12px;
		padding: 0;
		border-radius: 0;
		background: var(--neutral--50);
		margin-bottom: 12px;

		&__cell--plugin,
		&__cell--forms,
		&__cell--status {
			width: 100%;
			justify-content: flex-start;
		}
		&__cell--actions {
			width: 100%;
		}

		&__cell--forms,
		&__cell--status {
			align-items: flex-start;
		}

		&__cell--actions {
			padding-right: 0;
		}

		&__cell--plugin {
			padding-left: 0;
		}

		&__mobile-label {
			display: inline-block;
		}
	}
}
</style>
