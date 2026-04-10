<script setup lang="ts">
import { ref } from 'vue';

import FAQ from '@/components/FAQ.vue';
import Hero from '@/components/Hero.vue';
import { useToast } from '@/composables/useToast';
import { connectFaqData } from '@/data/faq';
import { reachRepo } from '@/data/repositories/reachRepo';
import { useGeneralDataStore } from '@/stores';
import { translate } from '@/utils/translate';

const TRUSTED_AUTH_DOMAINS = /^https:\/\/auth\.hostinger\.(dev|com)/;
const PREVIEW_DOMAINS = /hostingersite\.com/i;

const { showError } = useToast();

const generalDataStore = useGeneralDataStore();

const isConnectedToAnotherSite = ref(false);
const isButtonLoading = ref(false);
const domain = window.location.hostname;
const rawDomain = generalDataStore.rawDomain;

const handleGetStarted = async () => {
	isButtonLoading.value = true;

	const [data, error] = await reachRepo.generateAuthUrl();

	isButtonLoading.value = false;

	if (error || !data) {
		showError(error?.message || translate('hostinger_reach_error_message'));

		return;
	}

	if (PREVIEW_DOMAINS.test(domain)) {
		window.location.href = `https://hpanel.hostinger.com/websites/${encodeURIComponent(rawDomain)}`;

		return;
	}

	if (data.authUrl && TRUSTED_AUTH_DOMAINS.test(data.authUrl)) {
		window.location.href = data.authUrl;
	} else {
		showError(translate('hostinger_reach_error_message'));
	}
};
</script>

<template>
	<div class="welcome-view">
		<Hero
			:is-connected-to-another-site="isConnectedToAnotherSite"
			:is-button-loading="isButtonLoading"
			:domain="domain"
			:is-temporary="PREVIEW_DOMAINS.test(domain)"
			:on-get-started="handleGetStarted"
		/>
		<div class="faq-wrap">
			<FAQ :faq-data="connectFaqData" />
		</div>
	</div>
</template>

<style scoped lang="scss">
.welcome-view {
	min-height: 100vh;
	padding: 0 16px;

	@media (max-width: 480px) {
		padding: 0 12px;
	}
}

@media (max-width: 320px) {
	.welcome-view {
		padding: 0 8px;
	}
}

.faq-wrap {
	max-width: 780px;
	margin: 24px auto;
}
</style>
