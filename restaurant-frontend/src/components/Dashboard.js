import React, { useState, useEffect } from 'react';
import { Container, Row, Col, Card, Navbar, Nav } from 'react-bootstrap';
import { itemsAPI, ordersAPI } from '../services/api';

const Dashboard = ({ user, onLogout }) => {
  const [stats, setStats] = useState({
    totalItems: 0,
    totalOrders: 0,
    pendingOrders: 0,
    todayRevenue: 0
  });

  useEffect(() => {
    fetchStats();
  }, []);

  const fetchStats = async () => {
    try {
      // You'll need to create these API endpoints
      const [itemsRes, ordersRes] = await Promise.all([
        itemsAPI.getAll(),
        ordersAPI.getAll()
      ]);

      const items = itemsRes.data;
      const orders = ordersRes.data;

      setStats({
        totalItems: items.length,
        totalOrders: orders.length,
        pendingOrders: orders.filter(o => o.status === 'pending').length,
        todayRevenue: orders
          .filter(o => new Date(o.created_at).toDateString() === new Date().toDateString())
          .reduce((sum, order) => sum + parseFloat(order.total_amount), 0)
      });
    } catch (error) {
      console.error('Error fetching stats:', error);
    }
  };

  return (
    <>
      <Navbar bg="dark" variant="dark" expand="lg">
        <Container>
          <Navbar.Brand>
            <i className="fas fa-utensils me-2"></i>
            Restaurant System
          </Navbar.Brand>
          <Navbar.Toggle />
          <Navbar.Collapse className="justify-content-end">
            <Nav>
              <Navbar.Text className="me-3">
                Welcome, {user.display_name}
              </Navbar.Text>
              <Button variant="outline-light" size="sm" onClick={onLogout}>
                Logout
              </Button>
            </Nav>
          </Navbar.Collapse>
        </Container>
      </Navbar>

      <Container className="mt-4">
        <Row>
          <Col md={3} className="mb-4">
            <Card className="text-center">
              <Card.Body>
                <i className="fas fa-cube fa-2x text-primary mb-2"></i>
                <h3>{stats.totalItems}</h3>
                <Card.Text>Menu Items</Card.Text>
              </Card.Body>
            </Card>
          </Col>

          <Col md={3} className="mb-4">
            <Card className="text-center">
              <Card.Body>
                <i className="fas fa-shopping-cart fa-2x text-success mb-2"></i>
                <h3>{stats.totalOrders}</h3>
                <Card.Text>Total Orders</Card.Text>
              </Card.Body>
            </Card>
          </Col>

          <Col md={3} className="mb-4">
            <Card className="text-center">
              <Card.Body>
                <i className="fas fa-clock fa-2x text-warning mb-2"></i>
                <h3>{stats.pendingOrders}</h3>
                <Card.Text>Pending Orders</Card.Text>
              </Card.Body>
            </Card>
          </Col>

          <Col md={3} className="mb-4">
            <Card className="text-center">
              <Card.Body>
                <i className="fas fa-chart-line fa-2x text-info mb-2"></i>
                <h3>ETB {stats.todayRevenue}</h3>
                <Card.Text>Today's Revenue</Card.Text>
              </Card.Body>
            </Card>
          </Col>
        </Row>

        <Row>
          <Col md={6}>
            <Card>
              <Card.Header>
                <h5 className="mb-0">Quick Actions</h5>
              </Card.Header>
              <Card.Body>
                <div className="d-grid gap-2">
                  <Button variant="primary" size="lg">
                    <i className="fas fa-plus me-2"></i>
                    New Order
                  </Button>
                  <Button variant="success" size="lg">
                    <i className="fas fa-cube me-2"></i>
                    Manage Menu
                  </Button>
                  <Button variant="info" size="lg">
                    <i className="fas fa-chart-bar me-2"></i>
                    View Reports
                  </Button>
                </div>
              </Card.Body>
            </Card>
          </Col>
        </Row>
      </Container>
    </>
  );
};

export default Dashboard;