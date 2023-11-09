# RealPad to MailKit exporter

![RealPad & MailKit logo](realpad2mailkit.svg?raw=true)

Obtain Email contacty from realpad by project/tags and import it into MailKit and send report in XLS format

## Configuration

You can configure this connector by configuration keys in system environment or .env file

- **REALPAD_USERNAME** 
- **REALPAD_PASSWORD**
- **REALPAD_TAG** - only tag to export (do not use for full export)
- **REALPAD_PROJECT** - only project substring to export (do not use for full export)
- **MAILKIT_APPID** - grab on [integration](https://app.mailkit.eu/action,setting/section,6/action2,integration) page
- **MAILKIT_MD5** - grab on [integration](https://app.mailkit.eu/action,setting/section,6/action2,integration) page
- **MAILKIT_MAILINGLIST** - requied, must exists

## Installation

```shell
sudo apt install lsb-release wget apt-transport-https bzip2


wget -qO- https://repo.vitexsoftware.com/keyring.gpg | sudo tee /etc/apt/trusted.gpg.d/vitexsoftware.gpg
echo "deb [signed-by=/etc/apt/trusted.gpg.d/vitexsoftware.gpg]  https://repo.vitexsoftware.com  $(lsb_release -sc) main" | sudo tee /etc/apt/sources.list.d/vitexsoftware.list
sudo apt update

sudo apt install realpad2mailkit
```

