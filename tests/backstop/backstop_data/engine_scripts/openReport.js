module.exports = async (page, scenario, vp) => {
  const { exec } = require('child_process');

  exec(`
    rm -rf ~/spotdeals-backstop-report &&
    mkdir -p ~/spotdeals-backstop-report &&
    cp -a /var/www/html/spotdeals/tests/backstop/backstop_data/. ~/spotdeals-backstop-report/ &&
    firefox ~/spotdeals-backstop-report/html_report/index.html >/dev/null 2>&1 &
  `);
};
