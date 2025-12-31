# FOSSBilling EPP Registrar

[![StandWithUkraine](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/badges/StandWithUkraine.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

[![SWUbanner](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/banner2-direct.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

A generic FOSSBilling registrar module for connecting to any domain registry that uses the EPP protocol.

This module is designed to work with both gTLD and ccTLD registries and provides a flexible foundation for EPP-based domain management in FOSSBilling.

## Registry Support

| Registry | TLDs | Profile | Needs |
|----------|----------|----------|----------|
| Generic RFC EPP | any | | |
| AFNIC | .fr/others | FR | |
| Caucasus Online | .ge | | |
| CentralNic | all | | Set AuthInfo on Request |
| CoCCA | all | | Set AuthInfo on Request |
| CORE/Knipp | all | | |
| Domicilium | .im | | |
| DRS.UA | all | | | |
| EURid | .eu | EU | |
| GoDaddy Registry | all | | |
| Google Nomulus | all | | |
| Hostmaster | .ua | UA | |
| Identity Digital | all | | |
| IT.COM | all | | |
| Namingo | all | | |
| NASK | .pl | PL | |
| NIC Chile | .cl | | |
| NIC Mexico | .mx | MX | |
| Regtons | all | | |
| RoTLD | .ro | | |
| RyCE | all | | |
| SIDN | all | | |
| SWITCH | .ch, .li | SWITCH | Set AuthInfo on Request |
| Tucows Registry | all | | |
| Verisign | all | VRSN | |
| ZADNA | .za | | |
| ZDNS | all | | |

## Installation

1. Use our automated installer, or continue with steps 2-5 below.

```bash
wget https://raw.githubusercontent.com/getpinga/fossbilling-epp-rfc/main/install_epp_module.sh -O install_epp_module.sh && chmod +x install_epp_module.sh && ./install_epp_module.sh
```

2. Download this repository which contains the epp.php file. After successfully downloading the repository, move the epp.php file into the `[FOSSBilling]/library/Registrar/Adapter` directory.

Next, rename `epp.php` as `YourRegistryName.php`. Please ensure to replace "**YourRegistryName**" with the actual name of your registry.

Proceed to open the newly renamed file and locate the phrase "**Registrar_Adapter_EPP**". Replace it with "**Registrar_Adapter_YourRegistryName**".

3. The synchronization script **eppSync.php** needs to be placed in the main `[FOSSBilling]` directory.

Rename `eppSync.php` to `YourRegistryNameSync.php`.

Edit `eppSync.php` and replace **Epp** in the line `$registrar = "Epp";` with the name of your registry provided in step 2.

4. Set up a cron job that runs the sync module twice a day. Open crontab using the command `crontab -e` in your terminal.

Add the following cron job:

`0 0,12 * * * php /var/www/html/YourRegistryNameSync.php`

This command schedules the synchronization script to run once every 12 hours (at midnight and noon).

5. If EPP Client is not yet installed, create the Namingo directory and install it using Composer:

```bash
mkdir -p /var/www/html/namingo
cd /var/www/html/namingo
composer require pinga/tembo
```

## Activation

1. Within FOSSBilling, go to **System -> Domain Registration -> New Domain Registrar** and activate the new domain registrar.

2. Head to the "**Registrars**" tab. Here, you'll need to enter your specific configuration details, including the path to your SSL certificate and key.

3. Add a new Top Level Domain (TLD) using your module from the "**New Top Level Domain**" tab. Make sure to configure all necessary details, such as pricing, within this tab.

## Upgrading from v1.0.0

1. **Replace the module script**
   - Copy the latest version of the renamed module script into your FOSSBilling modules directory, overwriting the existing file.
   - **Important:** ensure the filename stays **exactly the same** as the current one in your modules directory (do not change the name).

2. **Re-run Step 5 (install/update EPP Client)**
   - If EPP Client is not installed yet, it will be installed.
   - If it is already installed, Composer will update/ensure dependencies as needed.

## Troubleshooting

If you experience problems connecting to your EPP server or syncing domains, work through the checklist below.

1. **Network access / allowlisting**
   - Ensure the server’s outbound IP(s) are allowlisted by the registry EPP endpoint (both **IPv4** and **IPv6**, if applicable).

2. **IPv6 considerations**
   - Confirm both sides support IPv6 if you intend to use it.
   - If you encounter IPv6-related connection issues, temporarily **disable IPv6** on the client side and retry.

3. **Reload after changes**
   - After updating configuration or replacing module files, restart the PHP runtime to ensure changes take effect.

4. **TLS certificates and permissions**
   - Verify the certificate and key paths are correct and readable by the web server user.
   - Example (Debian/Ubuntu):
     ```bash
     chown www-data:www-data cert.pem key.pem
     chmod 600 cert.pem key.pem
     ```

5. **Registrar prefix**
   - Ensure the module is configured with the correct **registrar prefix** for your registry connection.

6. **Transfer AuthInfo not returned by registry**
   - Some registries (e.g. **CentralNic**, **CoCCA**) may not return the transfer AuthInfo code via standard `domain:info`.
   - If your module does not display the transfer code, enable the option **“Set AuthInfo on Request”** in the module configuration. This forces the module to set/generate AuthInfo when requested, so it can be displayed/managed consistently.

## Support

Your feedback and inquiries are invaluable to Namingo's evolutionary journey. If you need support, have questions, or want to contribute your thoughts:

- **Email**: Feel free to reach out directly at [help@namingo.org](mailto:help@namingo.org).

- **Discord**: Or chat with us on our [Discord](https://discord.gg/97R9VCrWgc) channel.
  
- **GitHub Issues**: For bug reports or feature requests, please use the [Issues](https://github.com/getnamingo/fossbilling-epp-registrar/issues) section of our GitHub repository.

We appreciate your involvement and patience as Namingo continues to grow and adapt.

## 💖 Support This Project

If you find FOSSBilling EPP Registrar useful, consider donating:

- [Donate via Stripe](https://donate.stripe.com/7sI2aI4jV3Offn28ww)
- BTC: `bc1q9jhxjlnzv0x4wzxfp8xzc6w289ewggtds54uqa`
- ETH: `0x330c1b148368EE4B8756B176f1766d52132f0Ea8`

## Licensing

FOSSBilling EPP Registrar is licensed under the MIT License.