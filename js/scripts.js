
document.addEventListener('DOMContentLoaded', function() {

  var messages = document.getElementById('messages');

  var installationsText = document.getElementById('connector-installed-txt');
  var contentBlockManage = document.getElementById('content-block-manage');

  var showButton = document.getElementById('showButton');
  var bridgeStoreKey = document.getElementById('bridgeStoreKey');
  var storeKey = document.getElementById('storeKey');
  var storeBlock = document.querySelectorAll('.store-key')[0];
  var classMessage = document.querySelectorAll('.bridge--connectormessage')[0];
  var progress = document.querySelectorAll('.progress')[0];
  var additionalInfo = document.getElementById('additionalInfo');
  var bridgeUrl = document.getElementById('bridgeUrlVal');

  var timeDelay = 500;
  var messageClear;

  var bridgeConnectionInstall = document.getElementById('bridgeConnectionInstall');
  var bridgeConnectionUninstall = document.getElementById('bridgeConnectionUninstall');

  var updateBridgeStoreKey = document.getElementById('updateBridgeStoreKey');
  classMessage.style.opacity = '0';
  classMessage.style.visibility = 'hidden';

  if (showButton.value == 'install') {
    installationsText.style.visibility = 'visible';
    contentBlockManage.style.height = '0';
    contentBlockManage.style.visibility = 'hidden';
    installationsText.style.height = 'auto';
    installationsText.style.margin = 'inherit';
    storeBlock.style.visibility = 'hidden';
    updateBridgeStoreKey.style.visibility = 'hidden';
    bridgeConnectionUninstall.style.display = 'none';
    bridgeConnectionInstall.style.display = 'inline-block';
  } else {
    installationsText.style.visibility = 'hidden';
    contentBlockManage.style.height = 'auto';
    contentBlockManage.style.visibility = 'visible';
    installationsText.style.height = '0';
    installationsText.style.margin = '0';
    storeBlock.style.visibility = 'visible';
    updateBridgeStoreKey.style.visibility = 'visible';
    bridgeConnectionInstall.style.display = 'none';
    bridgeConnectionUninstall.style.display = 'inline-block';
  }

  function statusMessage(message, status) {
    var timeout = 2500;

    if (status == 'success') {
      classMessage.classList.remove('bridge_error');
      classMessage.classList.remove('bridge_warning');
    } else {
      if (status == 'error') {
        classMessage.classList.add('bridge_error');
      } else {
        timeout = 3500;
        classMessage.classList.add('bridge_warning');
      }
    }
    classMessage.innerHTML = '<span>' + message + '</span>';
    classMessage.style.display = 'block';


    fadeIn(classMessage, timeout, function () {
      fadeOut(classMessage, 500);
    });

    clearTimeout(messageClear);
    messageClear = setTimeout(function(){
      classMessage.innerHTML = '';
    }, timeout);
  }

  var setupButtons = document.querySelectorAll('.btn-setup');
  setupButtons.forEach(function(button) {
    button.addEventListener('click', function() {
      var self = this;
      self.disabled = true;
      progress.style.display = 'block';
      var install = 'install';

      if (showButton.value == 'uninstall') {
        this.innerText = 'Disconnecting...';
        install = 'remove';
      } else {
        this.innerText = 'Connecting...';
      }

      var originalText = this.innerText;
      var xhr = new XMLHttpRequest();
      xhr.open('POST', A2CAjax.ajaxurl, true);
      xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
      xhr.onload = function() {
        if (xhr.status === 200) {
          self.disabled = false;
          progress.style.display = 'none';
          var data = JSON.parse(xhr.responseText);

          if (install == 'install') {
            if (data.status.success !== true) {
              if (data.warning !== true) {
                bridgeConnectionInstall.innerText = 'Connect';
                bridgeConnectionUninstall.innerText = 'Disconnect';
                statusMessage('Can not install Connector' + '\r\n' + data.status.message, 'error');
                return;
              } else {
                statusMessage('Connector Installed with warning: ' + '\r\n' + data.status.message, 'warning');
              }
            }

            updateStoreKey(data.data.storeKey);
            bridgeUrl.innerHTML = data.data.bridgeUrl;
            fadeOut(installationsText, timeDelay);
            installationsText.style.height = '0';
            installationsText.style.margin = '0';
            contentBlockManage.style.height = 'auto';
            fadeIn(contentBlockManage, timeDelay);
            fadeIn(storeBlock, 1000);
            fadeIn(updateBridgeStoreKey, 1000);
            showButton.value = 'uninstall';
            bridgeConnectionInstall.style.display = 'none';
            bridgeConnectionUninstall.style.display = 'inline-block';
            bridgeConnectionInstall.innerText = 'Connect';
            bridgeConnectionUninstall.innerText = 'Disconnect';

            if (data.status.custom === true) {
              additionalInfo.style.display = 'block';
            } else {
              additionalInfo.style.display = 'none';
            }

            if (data.warning !== true) {
              statusMessage('Connector Installed Successfully', 'success');
            }
          } else {
            if (data.status.success !== true) {
              if (data.warning !== true) {
                bridgeConnectionInstall.innerText = 'Connect';
                bridgeConnectionUninstall.innerText = 'Disconnect';
                statusMessage(data.status.message, 'error');
                return;
              } else {
                statusMessage(data.status.message, 'warning');
              }
            }

            fadeOut(contentBlockManage, timeDelay);
            contentBlockManage.style.height = '0';
            installationsText.style.height = 'auto';
            installationsText.style.margin = 'inherit';
            fadeIn(installationsText, timeDelay);
            fadeOut(storeBlock, 'fast');
            fadeOut(updateBridgeStoreKey, 'fast');
            showButton.value = 'install';
            bridgeConnectionUninstall.style.display = 'none';
            bridgeConnectionInstall.style.display = 'inline-block';
            bridgeConnectionInstall.innerText = 'Connect';
            bridgeConnectionUninstall.innerText = 'Disconnect';
            additionalInfo.style.display = 'none';

            if (data.warning !== true) {
              statusMessage('Connector Uninstalled Successfully', 'success');
            }
          }
        } else {
          bridgeConnectionInstall.innerText = 'Connect';
          bridgeConnectionUninstall.innerText = 'Disconnect';
          statusMessage('Can\'t install Connector', 'error');
        }
      };

      xhr.send('action=A2CBCbridge_action&connector_action=' + install + 'Bridge&security=' + A2CAjax.nonce);
    });
  });

  updateBridgeStoreKey.addEventListener('click', function() {
    var originalText = this.innerText;
    this.innerText = 'Changing...';

    var xhr = new XMLHttpRequest();
    xhr.open('POST', A2CAjax.ajaxurl, true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function() {
      if (xhr.status === 200) {
        var data = JSON.parse(xhr.responseText);
        if (data.status.success !== true) {
          if (data.warning !== true) {
            updateBridgeStoreKey.innerText = originalText;
            statusMessage(data.status.message, 'error');
            return;
          } else {
            statusMessage(data.status.message, 'warning');
          }
        }

        updateStoreKey(data.data.storeKey);

        if (data.warning !== true) {
          statusMessage('Store key updated successfully!', 'success');
        }
      } else {
        statusMessage('Can\'t update store key', 'error');
      }

      updateBridgeStoreKey.innerText = originalText;
    };

    xhr.send('action=A2CBCbridge_action&connector_action=updateToken&security=' + A2CAjax.nonce);
  });

  function updateStoreKey(store_key) {
    storeKey.innerHTML = store_key;
  }

  function fadeIn(element, duration, callback) {
    element.style.transition = 'opacity ' + duration + 'ms';
    element.style.opacity = '1';
    element.style.visibility = 'visible';

    element.addEventListener('transitionend', function() {
      element.style.transition = '';

      if (typeof callback === 'function') {
        callback();
      }
    }, { once: true });
  }

  function fadeOut(element, duration) {
    element.style.transition = 'opacity ' + duration + 'ms';
    element.style.opacity = '0';

    element.addEventListener('transitionend', function() {
      element.style.visibility = 'hidden';
    }, { once: true });
  }

});