<?php

$text['title-x_provision_overlay']['en-us'] = "Provision Templates";
$text['title-x_provision_overlay_edit']['en-us'] = "Provision Template Override";
$text['title-x_provision_overlay_assets']['en-us'] = "Provision Branding";
$text['title-x_provision_overlay_build']['en-us'] = "Provision Template Build";

$text['header-x_provision_overlay']['en-us'] = "Provision Templates";
$text['header-x_provision_overlay_edit']['en-us'] = "Provision Template Override";
$text['header-x_provision_overlay_assets']['en-us'] = "Provision Branding";
$text['header-x_provision_overlay_build']['en-us'] = "Provision Template Build";
$text['header-new_file']['en-us'] = "New Override";
$text['header-upload_asset']['en-us'] = "Upload Image";
$text['header-apply_setting']['en-us'] = "Apply to Vendor Settings";
$text['header-verify']['en-us'] = "Verify";

$text['description-x_provision_overlay']['en-us'] = "Override provisioning template files for all domains (global) or for this domain only, without changing the upstream templates. Files that are not overridden keep receiving upstream updates.";
$text['description-x_provision_overlay_assets']['en-us'] = "Logos and wallpapers for the phones of this domain. Phones download them from the URL shown; use the URL in a vendor setting or in a template override.";
$text['description-new_file']['en-us'] = "Open the editor for a vendor, model and file name. A template that does not exist upstream is created as a new template; its vendor must exist under Device Vendors.";
$text['description-code_execution']['en-us'] = "Templates are executed by the template engine on the server. Only trusted administrators should be allowed to edit them.";
$text['description-asset_file']['en-us'] = "PNG, JPEG, BMP or GIF, up to {max} MB and 4096 x 4096 pixels. The image is re-encoded in the same format when saved. SVG is not accepted.";
$text['description-asset_name']['en-us'] = "Optional. Lower case letters, digits, dash and underscore. The extension is set from the image type. Uploading with an existing name replaces that image.";
$text['description-apply_setting']['en-us'] = "Write the URL of an image into a vendor setting for this domain. The setting is created as a domain setting and can be changed under Domain Settings.";
$text['description-template_snippet']['en-us'] = "For templates without a matching setting, add a domain override that references the image URL, for example:";

$text['label-template']['en-us'] = "Template";
$text['label-vendor']['en-us'] = "Vendor";
$text['label-model']['en-us'] = "Model";
$text['label-file']['en-us'] = "File";
$text['label-status']['en-us'] = "Status";
$text['label-actions']['en-us'] = "Actions";
$text['label-scope']['en-us'] = "Scope";
$text['label-override']['en-us'] = "Override";
$text['label-new_override']['en-us'] = "New override (not saved yet)";
$text['label-no_upstream']['en-us'] = "This file does not exist upstream. Saving creates an override without an upstream counterpart.";
$text['label-diff_upstream']['en-us'] = "Changes compared to upstream";
$text['label-diff_global']['en-us'] = "Changes compared to the global override";
$text['label-diff_identical']['en-us'] = "The override is identical to its base file.";
$text['label-diff_too_large']['en-us'] = "The changes are too large to display.";
$text['label-upstream_source']['en-us'] = "Upstream file (read only)";
$text['label-global_source']['en-us'] = "Global override (read only)";
$text['label-no_overrides']['en-us'] = "No overrides. Select a vendor or all templates to create one.";
$text['label-no_templates']['en-us'] = "No templates found.";
$text['label-build']['en-us'] = "Build";
$text['label-builds_on_disk']['en-us'] = "Builds on Disk";
$text['label-built_from']['en-us'] = "Built from Commit";
$text['label-upstream_commit']['en-us'] = "Upstream Commit";
$text['label-generated_at']['en-us'] = "Generated";
$text['label-upstream_changed']['en-us'] = "The upstream templates changed since this build. It is rebuilt automatically every day, or rebuild now.";
$text['label-no_build']['en-us'] = "No build exists yet.";
$text['label-overlay_disabled']['en-us'] = "The template overlay is disabled. Phones use the upstream templates.";
$text['label-link_problem']['en-us'] = "The merged template directory is not active. Check the build page.";
$text['label-link']['en-us'] = "Template Directory";
$text['label-disabled']['en-us'] = "Disabled";
$text['label-yes']['en-us'] = "Yes";
$text['label-no']['en-us'] = "No";
$text['label-problem']['en-us'] = "Problem";
$text['label-warning']['en-us'] = "Warning";
$text['label-verify_ok']['en-us'] = "No problems found.";
$text['label-build_warnings']['en-us'] = "Warnings from the current build";
$text['label-count_templates']['en-us'] = "Templates";
$text['label-count_upstream_files']['en-us'] = "Upstream Files";
$text['label-count_global_overrides']['en-us'] = "Global Overrides";
$text['label-count_domains']['en-us'] = "Domains with Overrides";
$text['label-count_domain_overrides']['en-us'] = "Domain Overrides";
$text['label-asset_file']['en-us'] = "Image";
$text['label-asset_name']['en-us'] = "Name";
$text['label-asset_type']['en-us'] = "Type";
$text['label-asset_dimensions']['en-us'] = "Dimensions";
$text['label-asset_size']['en-us'] = "Size";
$text['label-asset_url']['en-us'] = "URL";
$text['label-uploaded']['en-us'] = "Uploaded";
$text['label-no_assets']['en-us'] = "No images have been uploaded.";
$text['label-no_vendor_settings']['en-us'] = "No vendor image settings are available.";

$text['option-overrides_only']['en-us'] = "Overrides only";
$text['option-all_templates']['en-us'] = "All templates";
$text['option-scope_domain']['en-us'] = "This domain";
$text['option-scope_global']['en-us'] = "All domains (global)";

$text['status-upstream']['en-us'] = "Upstream";
$text['status-global_override']['en-us'] = "Global override";
$text['status-domain_override']['en-us'] = "Domain override";
$text['status-orphaned']['en-us'] = "No upstream counterpart";
$text['status-template_missing']['en-us'] = "Upstream template missing";

$text['warning-vendor_missing']['en-us'] = "These vendors have no enabled Device Vendors entry, so their templates are not offered in the device template list";

$text['button-assets']['en-us'] = "Branding";
$text['button-build_status']['en-us'] = "Build";
$text['button-edit_domain']['en-us'] = "Domain";
$text['button-edit_global']['en-us'] = "Global";
$text['button-open_editor']['en-us'] = "Open Editor";
$text['button-back']['en-us'] = "Back";
$text['button-save']['en-us'] = "Save";
$text['button-reset']['en-us'] = "Reset to Upstream";
$text['button-delete']['en-us'] = "Delete";
$text['button-upload']['en-us'] = "Upload";
$text['button-apply']['en-us'] = "Apply";
$text['button-rebuild']['en-us'] = "Rebuild Now";
$text['button-rollback']['en-us'] = "Roll Back";

$text['confirm-reset']['en-us'] = "Delete this override?";
$text['confirm-delete_asset']['en-us'] = "Delete this image? Phones and settings that use its URL will no longer find it.";
$text['confirm-rollback']['en-us'] = "Switch to the previous build?";

$text['message-saved']['en-us'] = "Override saved and templates rebuilt.";
$text['message-reset']['en-us'] = "Override deleted and templates rebuilt.";
$text['message-built']['en-us'] = "Templates rebuilt";
$text['message-rolled_back']['en-us'] = "Switched to the previous build";
$text['message-asset_saved']['en-us'] = "Image saved";
$text['message-asset_deleted']['en-us'] = "Image deleted.";
$text['message-setting_applied']['en-us'] = "Domain setting saved.";
$text['message-invalid_token']['en-us'] = "Invalid token. Please try again.";

$text['error-permission']['en-us'] = "Access denied.";
$text['error-invalid_name']['en-us'] = "Invalid vendor, model or file name.";
$text['error-template_unknown']['en-us'] = "That template does not exist. Use New Override to create a new template.";
$text['error-vendor_unknown']['en-us'] = "A new template needs a vendor that exists under Device Vendors.";
$text['error-content_too_large']['en-us'] = "The template is larger than 1 MB.";
$text['error-content_invalid']['en-us'] = "The template must be UTF-8 text without NUL bytes.";
$text['error-lock']['en-us'] = "Another change is in progress. Please try again.";
$text['error-write']['en-us'] = "The override could not be written.";
$text['error-build']['en-us'] = "The override was saved but the templates could not be rebuilt; it is applied by the next successful build.";
$text['error-not_found']['en-us'] = "Not found.";
$text['error-asset_no_gd']['en-us'] = "The PHP GD extension is required to process images.";
$text['error-asset_upload']['en-us'] = "The file upload failed.";
$text['error-asset_too_large']['en-us'] = "The file is too large.";
$text['error-asset_type']['en-us'] = "Only PNG, JPEG, BMP and GIF images are allowed.";
$text['error-asset_dimensions']['en-us'] = "The image dimensions are not allowed (maximum 4096 x 4096 pixels).";
$text['error-asset_name']['en-us'] = "Invalid image name.";
$text['error-asset_count']['en-us'] = "This domain already has the maximum of 50 images.";
$text['error-asset_process']['en-us'] = "The image could not be processed.";
$text['error-asset_store']['en-us'] = "The image could not be stored.";
$text['error-setting']['en-us'] = "The domain setting could not be saved.";
$text['error-setting_unknown']['en-us'] = "That setting cannot be set from here.";

?>
