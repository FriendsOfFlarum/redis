import app from 'flarum/admin/app';
import extendStatusWidget from './extenders/extendStatusWidget';
import extendDashboardPage from './extenders/extendDashboardPage';

export { default as extend } from './extend';

app.initializers.add('fof-redis', () => {
  extendStatusWidget();
  extendDashboardPage();
});
