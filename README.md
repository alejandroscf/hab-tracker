HAB-Tracker
===========

HAB-Tracker is a ugly bit of JS to display a map with the location and predict the landing point.

API keys
--------

In order to get the ballon position and prediction we'll query two services APRS.fi and CUSF / SondeHub Predictor through theirs APIs.

You need API keys in order to query them:
- aprs.fi: Register and request API token: https://aprs.fi/page/api

- Predictor: Do not require API token but use with care:
  - https://github.com/projecthorus/tawhiri/#sondehub-tawhiri-instance
  - https://tawhiri.readthedocs.io/en/latest/api.html

Quick Setup guide:
------------------
1. Clone the repo.
1. Rename settings.php.sample to settings.php and set your keys and other parameters.
1. Deploy it in your server or hosting.
