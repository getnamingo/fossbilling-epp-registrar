#!/bin/bash

# Function to make a string filename safe
sanitize_filename() {
    echo "$1" | tr -cd '[:alnum:]_-' 
}

# Ask for registry name
echo "Enter the name of the registry you are installing this module for:"
read -r registry_name
safe_registry_name=$(sanitize_filename "$(echo "$registry_name" | sed -E 's/^(.)/\U\1/')") 

# Ask for FOSSBilling directory
echo "Enter the path to the FOSSBilling directory (default is /var/www):"
read -r fossbilling_path
fossbilling_path=${fossbilling_path:-/var/www}

# Clone the repository to /tmp
git clone https://github.com/getnamingo/fossbilling-epp-registrar /tmp/fossbilling-epp-registrar

# Rename and move the epp.php file
mv /tmp/fossbilling-epp-registrar/epp.php "$fossbilling_path/library/Registrar/Adapter/${safe_registry_name}.php"

# Edit the newly copied file
sed -i "s/Registrar_Adapter_EPP/Registrar_Adapter_${safe_registry_name}/g" "$fossbilling_path/library/Registrar/Adapter/${safe_registry_name}.php"

# Move and rename eppSync.php
mv /tmp/fossbilling-epp-registrar/eppSync.php "$fossbilling_path/${safe_registry_name}Sync.php"

# Edit the renamed eppSync.php
sed -i "s/\$registrar = \"Epp\";/\$registrar = \"${safe_registry_name}\";/g" "$fossbilling_path/${safe_registry_name}Sync.php"

# Add the cron job
(crontab -l 2>/dev/null; echo "0 0,12 * * * php $fossbilling_path/${safe_registry_name}Sync.php") | crontab -

namingo_dir="${fossbilling_path}/namingo"

if [ ! -d "${namingo_dir}/vendor/pinga/tembo" ]; then
    echo "Installing EPP Client in ${namingo_dir}..."

    mkdir -p "${namingo_dir}"
    cd "${namingo_dir}" || exit 1

    export COMPOSER_ALLOW_SUPERUSER=1
    composer require pinga/tembo --no-interaction --no-progress
else
    echo "EPP Client already installed in ${namingo_dir}"
fi

# Clean up
rm -rf /tmp/fossbilling-epp-registrar

# Final instructions
echo "Installation complete."
echo ""
echo "1. Activate the Domain Registrar Module:"
echo "Within FOSSBilling, go to System -> Domain Registration -> New Domain Registrar and activate the new domain registrar."
echo ""
echo "2. Registrar Configuration:"
echo "Next, head to the 'Registrars' tab. Here, you'll need to enter your specific configuration details, including the path to your SSL certificate and key."
echo ""
echo "3. Adding a New TLD:"
echo "Finally, add a new Top Level Domain (TLD) using your module from the 'New Top Level Domain' tab. Make sure to configure all necessary details, such as pricing, within this tab."