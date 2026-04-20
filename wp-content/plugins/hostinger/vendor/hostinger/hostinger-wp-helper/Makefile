PLUGIN_FILE_NAME := "hostinger-wp-helper.zip"
VERSION := 1.0.12
BUILD_DIR := hostinger-wp-helper-$(VERSION)
ZIP_FILE := $(BUILD_DIR).zip

.PHONY: clean-release
clean-release:
	@echo "Cleaning release files"
	@rm -rf ./$(BUILD_DIR)
	@rm -f ./$(ZIP_FILE)

.PHONY: build-release
build-release: clean-release
	@echo "Building release: $(ZIP_FILE)"; \
	composer install --no-dev -o; \
	mkdir -p $(BUILD_DIR); \
	rsync -r --exclude-from='BUILDEXCLUDE' ./ $(BUILD_DIR)/; \
	zip -r $(ZIP_FILE) $(BUILD_DIR); \
	rm -rf $(BUILD_DIR); \
	echo "Created: $(ZIP_FILE)"

.PHONY: build
build: build-release
	@echo "Build complete!"
